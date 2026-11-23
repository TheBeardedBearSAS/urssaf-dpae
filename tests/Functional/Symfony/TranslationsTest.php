<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Functional\Symfony;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Translation\TranslatorInterface;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Harness;

final class TranslationsTest extends TestCase
{
    /**
     * Codes that are technical (configuration, wire format, paths) and never shown to an end user. Besides the
     * configuration errors of the Domain, the Infrastructure adds the configuration of the HTTP client
     * (`base_uri.*`, `concentrator_port.*`: raised while the container is built) and the paths of the wire format of the
     * flow (`employer.siret`, `employee.nir`...: the location of a violation, not a code).
     */
    private const NOT_TRANSLATED = [
        'base_uri.insecure_scheme',
        'concentrator_port.out_of_range',
        'employee.nir',
        'employee.surname',
        'employer.phone',
        'employer.siret',
        'employer.street',
        'employer.town',
        'contract.type',
        'declaration_vocabulary.not_distinct',
        'deposit.invalid_max_attempts',
        'test_indicators.not_distinct',
        'violation.invalid_code',
        'violation.invalid_path',
        'workflow_policy.invalid_',
        'workflow_policy.invalid_max_deposit_attempts',
        'workflow_policy.invalid_max_polling_constraint_retries',
        'workflow_policy.invalid_polling_max_range',
        'workflow_policy.invalid_polling_retry_delay',
        'workflow_policy.invalid_reconciliation_skew',
    ];

    /** Locales shipped by the bundle. */
    private const LOCALES = ['en', 'fr'];

    /**
     * @return iterable<string, array{string}>
     */
    public static function locales(): iterable
    {
        foreach (self::LOCALES as $locale) {
            yield $locale => [$locale];
        }
    }

    #[DataProvider('locales')]
    public function testEveryViolationCodeOfTheDomainHasAMessage(string $locale): void
    {
        $messages = self::messages($locale);

        $missing = array_values(array_diff(self::codesInSources(), self::NOT_TRANSLATED, array_keys($messages)));

        static::assertSame([], $missing, 'Violation codes without a translation.');
    }

    #[DataProvider('locales')]
    public function testEveryMessageIsAFilledSentence(string $locale): void
    {
        foreach (self::messages($locale) as $code => $message) {
            static::assertMatchesRegularExpression('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/', $code);
            static::assertMatchesRegularExpression('/^\p{Lu}.+[.]$/u', $message, $code);
        }
    }

    #[DataProvider('locales')]
    public function testEveryLocaleHasTheKeysOfTheEnglishFileInTheSameOrder(string $locale): void
    {
        static::assertSame(array_keys(self::messages('en')), array_keys(self::messages($locale)));
    }

    public function testTheFrameworkFindsTheMessagesOfTheBundle(): void
    {
        $translator = Harness::bootDefault([])->privateService('translator', TranslatorInterface::class);

        static::assertSame('The flow is empty.', $translator->trans('flow.empty', [], 'urssaf_dpae', 'en'));
        static::assertSame('Le flux est vide.', $translator->trans('flow.empty', [], 'urssaf_dpae', 'fr'));
    }

    /**
     * @return array<string, string>
     */
    private static function messages(string $locale): array
    {
        // @mago-expect analysis:mixed-assignment YAML is untyped; every entry is checked below.
        $parsed = Yaml::parseFile(\dirname(__DIR__, 3) . '/src/Bridge/Symfony/Resources/translations/urssaf_dpae.' . $locale . '.yaml');
        static::assertIsArray($parsed);
        $messages = [];
        foreach ($parsed as $code => $message) { // @mago-expect analysis:mixed-assignment narrowed by the assertion below.
            static::assertIsString($message);
            $messages[(string) $code] = $message;
        }

        return $messages;
    }

    /**
     * @return list<string>
     */
    private static function codesInSources(): array
    {
        $codes = [];
        foreach (['Domain', 'Application', 'Infrastructure'] as $layer) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(\dirname(__DIR__, 3) . '/src/' . $layer, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($files as $file) {
                Assert::assertInstanceOf(SplFileInfo::class, $file);
                $matches = [];
                preg_match_all("/'([a-z][a-z0-9_]*(?:\\.[a-z][a-z0-9_]*)+)'/", (string) file_get_contents($file->getPathname()), $matches);
                array_push($codes, ...$matches[1]);
            }
        }

        return array_values(array_unique($codes));
    }
}
