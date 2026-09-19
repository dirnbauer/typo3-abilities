<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Http;

/**
 * The endpoints of the REST projection (WordPress Abilities REST API layout).
 */
enum RestEndpoint
{
    /** GET {base}/abilities */
    case Listing;

    /** GET {base}/abilities/{ns}/{name} */
    case Describe;

    /** GET|POST|DELETE {base}/abilities/{ns}/{name}/run — method by annotation */
    case Run;

    /** GET {base}/categories */
    case Categories;

    /** GET {base}/categories/{slug} */
    case Category;

    /** GET {base}/catalog — the ability catalogue of the whole installation */
    case Catalog;
}
