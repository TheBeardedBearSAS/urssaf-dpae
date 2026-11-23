<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Contract\Event;

use TheBeardedBear\UrssafDpae\Contract\Event\IntegrationEvent;

/**
 * An integration event the codec does not list: it must never be rebuilt from stored data.
 */
final readonly class ForeignEvent extends IntegrationEvent {}
