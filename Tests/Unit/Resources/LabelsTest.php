<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Tests\Unit\Resources;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Webconsulting\Abilities\Domain\RiskTier;

/**
 * Every label the backend module and its JavaScript ask for exists in
 * English and German, and every label file has a complete German twin.
 * A missing unit renders as an empty string, so this is the guard against a
 * blank badge or notification.
 */
final class LabelsTest extends TestCase
{
    private const string LANGUAGE = __DIR__ . '/../../../Resources/Private/Language/';

    #[Test]
    public function everyLabelTheModuleReferencesExistsInBothLanguages(): void
    {
        $english = self::unitIds(self::LANGUAGE . 'locallang_mod.xlf');
        $german = self::unitIds(self::LANGUAGE . 'de.locallang_mod.xlf');

        $referenced = self::referencedIds();
        self::assertGreaterThan(80, count($referenced), 'the scan found the module labels');
        foreach ($referenced as $id) {
            self::assertContains($id, $english, 'English label ' . $id);
            self::assertContains($id, $german, 'German label ' . $id);
        }
    }

    #[Test]
    #[DataProvider('labelFiles')]
    public function theGermanFileTranslatesEveryUnit(string $file): void
    {
        $germanFile = preg_replace('#([^/]+)$#', 'de.$1', $file);
        self::assertIsString($germanFile);
        self::assertSame(self::unitIds(self::LANGUAGE . $file), self::unitIds(self::LANGUAGE . $germanFile), $file);

        $xml = self::load(self::LANGUAGE . $germanFile);
        foreach ($xml->xpath('//x:trans-unit') ?: [] as $unit) {
            $unit->registerXPathNamespace('x', 'urn:oasis:names:tc:xliff:document:1.2');
            $target = $unit->xpath('x:target') ?: [];
            self::assertNotSame([], $target, $file . ' ' . $unit['id']);
            self::assertNotSame('', trim((string)$target[0]), $file . ' ' . $unit['id']);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function labelFiles(): iterable
    {
        yield 'module' => ['locallang_mod.xlf'];
        yield 'TCA' => ['locallang_db.xlf'];
        yield 'module registration' => ['Modules/abilities.xlf'];
    }

    #[Test]
    public function theModuleRegistrationLabelsUseTheV14Keys(): void
    {
        self::assertSame(['title', 'short_description', 'description'], self::unitIds(self::LANGUAGE . 'Modules/abilities.xlf'));
    }

    /**
     * @return list<string>
     */
    private static function referencedIds(): array
    {
        $template = (string)file_get_contents(__DIR__ . '/../../../Resources/Private/Templates/AbilitiesModule/Index.html');
        preg_match_all('/<f:translate key="\{ll\}([A-Za-z0-9_.]+)"/', $template, $tags);
        preg_match_all('/f:translate\(key: \'\{ll\}([A-Za-z0-9_.]+)\'/', $template, $inline);

        $javaScript = (string)file_get_contents(__DIR__ . '/../../../Resources/Public/JavaScript/registry.js');
        preg_match_all('/labels\.get\("([A-Za-z0-9_.]+)"/', $javaScript, $javaScriptLabels);

        $ids = array_merge($tags[1], $inline[1], $javaScriptLabels[1]);
        // Built from values: risk.{tier} (template and JavaScript) and the
        // catalogue's annotation.{flag}.
        foreach (RiskTier::cases() as $tier) {
            $ids[] = 'risk.' . $tier->value;
        }
        foreach (['readonly', 'destructive', 'idempotent'] as $flag) {
            $ids[] = 'annotation.' . $flag;
        }

        $ids = array_values(array_unique(array_filter($ids, static fn(string $id): bool => !str_ends_with($id, '.'))));
        sort($ids);

        return $ids;
    }

    /**
     * @return list<string>
     */
    private static function unitIds(string $file): array
    {
        $xml = self::load($file);

        return array_values(array_map(
            static fn(\SimpleXMLElement $unit): string => (string)$unit['id'],
            $xml->xpath('//x:trans-unit') ?: [],
        ));
    }

    private static function load(string $file): \SimpleXMLElement
    {
        $xml = simplexml_load_file($file);
        self::assertInstanceOf(\SimpleXMLElement::class, $xml, $file);
        $xml->registerXPathNamespace('x', 'urn:oasis:names:tc:xliff:document:1.2');

        return $xml;
    }
}
