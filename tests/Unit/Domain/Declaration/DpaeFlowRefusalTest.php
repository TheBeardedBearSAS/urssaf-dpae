<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Domain\Declaration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DpaeFlow;
use TheBeardedBear\UrssafDpae\Domain\Declaration\TestIndicators;
use TheBeardedBear\UrssafDpae\Domain\Exception\ValidationFailed;

final class DpaeFlowRefusalTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideRefusedFlows(): iterable
    {
        yield 'empty' => ['', 'flow.empty'];
        yield 'blank' => [" \t\r\n ", 'flow.empty'];
        yield 'byte order mark only' => ["\xEF\xBB\xBF", 'flow.empty'];
        yield 'flat flow' => [str_repeat('0', 990), 'flow.format_not_supported'];
        yield 'plain text' => ['hello', 'flow.format_not_supported'];
        yield 'text after whitespace' => ["  \n hello <FR_DUE_Upload/>", 'flow.format_not_supported'];
        yield 'text after byte order mark' => ["\xEF\xBB\xBFhello", 'flow.format_not_supported'];
        yield 'truncated byte order mark' => ["\xEF\xBB<FR_DUE_Upload/>", 'flow.format_not_supported'];
        yield 'utf-16 byte order mark' => ["\xFF\xFE<\0F\0", 'flow.format_not_supported'];
        yield 'broken tag' => ['<broken', 'flow.not_well_formed'];
        yield 'mismatching indicator' => ['<FR_DUE_Upload/>', 'flow.declaration_mode_mismatch'];
        yield 'nul byte' => ["<FR_DUE_Upload>\0</FR_DUE_Upload>", 'flow.not_well_formed'];
        yield 'unclosed' => ['<FR_DUE_Upload>', 'flow.not_well_formed'];
        yield 'two roots' => ['<FR_DUE_Upload/><FR_DUE_Upload/>', 'flow.not_well_formed'];
        yield 'bad encoding' => ["<FR_DUE_Upload>\xFF</FR_DUE_Upload>", 'flow.not_well_formed'];
        yield 'undefined entity' => ['<FR_DUE_Upload>&e;</FR_DUE_Upload>', 'flow.not_well_formed'];
        yield 'doctype' => ['<!DOCTYPE FR_DUE_Upload><FR_DUE_Upload/>', 'flow.dtd_not_allowed'];
        yield 'external entity' => [
            '<!DOCTYPE FR_DUE_Upload [<!ENTITY e SYSTEM "file:///etc/hostname">]><FR_DUE_Upload>&e;</FR_DUE_Upload>',
            'flow.dtd_not_allowed',
        ];
        yield 'internal entity' => [
            '<!DOCTYPE FR_DUE_Upload [<!ENTITY e "boom">]><FR_DUE_Upload>&e;</FR_DUE_Upload>',
            'flow.dtd_not_allowed',
        ];
        yield 'other root' => ['<Other/>', 'flow.unexpected_root'];
        yield 'prefixed root' => ['<p:FR_DUE_Upload xmlns:p="urn:x"/>', 'flow.unexpected_root'];
        yield 'case differs' => ['<fr_due_upload/>', 'flow.unexpected_root'];
    }

    #[DataProvider('provideRefusedFlows')]
    public function testInvalidFlowsAreRefused(string $bytes, string $code): void
    {
        try {
            DpaeFlow::fromXml($bytes, DeclarationMode::Test, TestIndicators::defaults());
            static::fail('Expected a refusal.');
        } catch (ValidationFailed $exception) {
            $errors = $exception->result()->errors();
            static::assertCount(1, $errors);
            static::assertSame($code, $errors[0]->code());
            static::assertSame('flow', $errors[0]->path());
            static::assertSame('validation.failed', $exception->violationCode());
            static::assertStringNotContainsString('boom', $errors[0]->message());
        }
    }

    public function testParsingDoesNotLeakLibxmlState(): void
    {
        $previous = libxml_use_internal_errors(false);
        try {
            $refused = false;
            try {
                DpaeFlow::fromXml('<broken', DeclarationMode::Test, TestIndicators::defaults());
            } catch (ValidationFailed) {
                $refused = true;
            }
            static::assertTrue($refused);
            static::assertFalse(libxml_use_internal_errors(false));
            static::assertSame([], libxml_get_errors());

            libxml_use_internal_errors(true);
            $refused = false;
            try {
                DpaeFlow::fromXml('<broken', DeclarationMode::Test, TestIndicators::defaults());
            } catch (ValidationFailed) {
                $refused = true;
            }
            static::assertTrue($refused);
            static::assertSame([], libxml_get_errors());
            DpaeFlow::fromXml('<FR_DUE_Upload><FR_DUE_Upload.Test.Indicator>TST</FR_DUE_Upload.Test.Indicator></FR_DUE_Upload>', DeclarationMode::Test, TestIndicators::defaults());
            static::assertTrue(libxml_use_internal_errors(false));
        } finally {
            libxml_use_internal_errors($previous);
        }
    }
}
