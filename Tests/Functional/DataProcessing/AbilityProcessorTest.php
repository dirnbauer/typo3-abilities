<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Tests\Functional\DataProcessing;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\Abilities\DataProcessing\AbilityProcessor;
use Webconsulting\Abilities\Tests\Support\TypeNarrowing;

/**
 * The Fluid surface: TypoScript input goes through stdWrap and schema
 * coercion, the result envelope lands in the processed data, only read-only
 * abilities may render, and the run is traced with surface "frontend".
 */
final class AbilityProcessorTest extends FunctionalTestCase
{
    use TypeNarrowing;

    protected array $testExtensionsToLoad = ['webconsulting/typo3-abilities'];

    private AbilityProcessor $processor;

    private ContentObjectRenderer $cObj;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content.csv');

        $processor = $this->get(AbilityProcessor::class);
        self::assertInstanceOf(AbilityProcessor::class, $processor);
        $this->processor = $processor;

        $this->cObj = GeneralUtility::makeInstance(ContentObjectRenderer::class);
        $this->cObj->setRequest(new ServerRequest('https://example.test/'));
        $this->cObj->start(['uid' => 1, 'title' => 'roadmap'], 'pages');
    }

    #[Test]
    public function runsAReadOnlyAbilityWithStdWrappedAndCoercedInput(): void
    {
        $processed = $this->processor->process($this->cObj, [], [
            'ability' => 'content/search',
            'as' => 'search',
            'input.' => [
                'term.' => ['field' => 'title'],
                'limit' => '1',
                'tables' => 'pages',
            ],
        ], ['keep' => 'me']);

        self::assertSame('me', $processed['keep']);
        $search = self::asArray($processed['search']);
        self::assertTrue($search['ok'], (string)json_encode($search));
        $data = self::asArray($search['data']);
        self::assertSame(1, $data['total']);
        self::assertSame('pages', self::asArray(self::asArray($data['results'])[0])['table']);

        $trace = $this->getConnectionPool()->getConnectionForTable('tx_abilities_trace')
            ->select(['surface', 'input'], 'tx_abilities_trace', ['ability' => 'content/search'])->fetchAssociative();
        self::assertIsArray($trace);
        self::assertSame('frontend', $trace['surface']);
        self::assertSame('{"term":"roadmap","limit":1,"tables":["pages"]}', $trace['input'], 'strings were coerced to the schema types before the run');
    }

    #[Test]
    public function deniedRunsAreAnEnvelopeNotAnException(): void
    {
        $processed = $this->processor->process($this->cObj, [], [
            'ability' => 'content/search',
            'input.' => ['term' => 'x'],
        ], []);

        $result = self::asArray($processed['ability']);
        self::assertFalse($result['ok']);
        self::assertSame('ability_invalid_input', $result['errorCode']);
    }

    #[Test]
    public function refusesUnknownAndWritingAbilities(): void
    {
        try {
            $this->processor->process($this->cObj, [], ['ability' => 'nope/nope'], []);
            self::fail('unknown ability must throw');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame(7480291070, $exception->getCode());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(7480291071);
        $this->processor->process($this->cObj, [], ['ability' => 'content/create-page-draft', 'input.' => ['parent' => '1', 'title' => 'x']], []);
    }
}
