<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Benchmark;

use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Wire\EnvoiXmlReader;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Wire\RetoursXmlReader;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Wire\WireDate;

/**
 * Decoding of the answers, from the raw XML: the fictitious fixtures of tests/Fixtures/wire (no official example is
 * published, api-reference.md section 5.9) and a large synthetic listing. The maximum extent of a listing is not
 * published (section 5.7); the large one holds 200 flux (400 returns, about 100 KB), a deliberately large page. Its
 * cost is linear (4 000 returns take about 0.2 s), so it stays small enough for the 500 revolutions of phpbench.json.
 */
final readonly class WireDecodingBench
{
    private const FIXTURES = __DIR__ . '/../Fixtures/wire/';
    private const FLUX_IN_LARGE_LISTING = 200;

    private WireDate $dates;

    private string $listing;

    private string $attributesListing;

    private string $largeListing;

    private string $receipt;

    private string $rejection;

    public function __construct()
    {
        $this->dates = WireDate::defaultZone();
        $this->listing = self::fixture('retours_children.xml');
        $this->attributesListing = self::fixture('retours_attributes.xml');
        $this->receipt = self::fixture('envoi_receipt.xml');
        $this->rejection = self::fixture('envoi_rejection.xml');

        $template = '<flux><id>FICTIF%016d</id>'
            . '<retour><publication>20220130083000</publication><production>20220130082500</production>'
            . '<nature>10</nature><statut>OK</statut><id>%010d</id></retour>'
            . '<retour><publication>20220131083000</publication><production>20220131082500</production>'
            . '<ops>OPS12</ops><nature>41</nature><statut>EN_COURS</statut></retour></flux>';
        $flux = '';
        for ($i = 1; $i <= self::FLUX_IN_LARGE_LISTING; ++$i) {
            $flux .= \sprintf($template, $i, $i);
        }
        $this->largeListing = '<?xml version="1.0" encoding="UTF-8"?><retours>' . $flux . '</retours>';
    }

    public function benchDecodeAListing(): void
    {
        RetoursXmlReader::read($this->listing, 'application/xml', $this->dates);
    }

    public function benchDecodeAListingWithAttributes(): void
    {
        RetoursXmlReader::read($this->attributesListing, 'application/xml', $this->dates);
    }

    public function benchDecodeALargeListing(): void
    {
        RetoursXmlReader::read($this->largeListing, 'application/xml', $this->dates);
    }

    public function benchDecodeAReceipt(): void
    {
        EnvoiXmlReader::receipt($this->receipt, 'application/xml', $this->dates);
    }

    public function benchDecodeARejectionNotice(): void
    {
        EnvoiXmlReader::rejection($this->rejection, 'application/xml', $this->dates);
    }

    private static function fixture(string $name): string
    {
        $bytes = file_get_contents(self::FIXTURES . $name);
        \assert(\is_string($bytes), 'The fixture is readable.');

        return $bytes;
    }
}
