<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\Infrastructure\Api;

use App\Module\Dunning\Domain\Claim;
use App\Module\Dunning\Domain\ClaimFilter;
use App\Module\Dunning\Domain\ClaimRepository;
use App\Module\Dunning\Domain\ClaimStep;
use App\Module\Dunning\Domain\DunningPermissions;
use App\Shared\Api\ApiPage;
use App\Shared\Api\ApiQuery;
use App\Shared\Api\ApiValue;
use App\Shared\Api\PublishesResource;
use App\Shared\Ui\Page;

/**
 * Forderungen mit ihrem Verlauf.
 *
 * **Hier steht das Zahlungsverhalten.** Faelligkeit, Verzugsbeginn, offener
 * Betrag und der Tag, an dem ausgeglichen wurde — daraus laesst sich
 * beantworten, wer wie schnell zahlt. Eine eigene Ressource fuer Zahlungen
 * gibt es deshalb nicht: sie waere dieselbe Auskunft von der anderen Seite.
 *
 * Die Stufen kommen mit: eine Forderung in der dritten Mahnstufe ist etwas
 * anderes als eine, die gerade erst faellig wurde.
 */
final readonly class ClaimsResource implements PublishesResource
{
    public function __construct(private ClaimRepository $claims)
    {
    }

    public function name(): string
    {
        return 'claims';
    }

    public function permission(): string
    {
        return DunningPermissions::VIEW;
    }

    /** Auch eine Mahnstufe: sie ist der Verlauf, den die Forderung zeigt. */
    public function identifies(object $entity): ?string
    {
        return match (true) {
            $entity instanceof Claim => $entity->id(),
            $entity instanceof ClaimStep => $entity->claim()->id(),
            default => null,
        };
    }

    public function page(ApiQuery $query): ApiPage
    {
        $filter = ClaimFilter::none();
        $page = Page::of($query->page, $this->claims->countMatching($filter));

        return new ApiPage(
            array_map(self::describe(...), $this->claims->matching($filter, $page)),
            $page->number,
            $page->pages,
            $page->total,
        );
    }

    public function one(string $id): ?array
    {
        $found = $this->claims->byId($id);

        return null === $found ? null : self::describe($found);
    }

    /**
     * @return array<string, mixed>
     */
    private static function describe(Claim $claim): array
    {
        $arrears = $claim->arrears();

        return [
            'id' => $claim->id(),
            'party_id' => $claim->debtor()->partyId(),
            'commercial' => $claim->debtor()->isCommercial(),
            'property_id' => $claim->source()->propertyId(),
            'unit_id' => $claim->source()->unitId(),
            'subject' => $claim->subject(),
            'due_on' => ApiValue::day($arrears->dueOn()),
            'default_from' => ApiValue::day($arrears->beginsOn()),
            'settled_on' => ApiValue::day($arrears->settledOn()),
            'open' => ApiValue::money($claim->open()),
            'created_at' => ApiValue::moment($claim->createdAt()),
            'steps' => array_map(self::describeStep(...), $claim->steps()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function describeStep(ClaimStep $step): array
    {
        return [
            'id' => $step->id(),
            'starts_on' => ApiValue::day($step->startsOn()),
            'open' => ApiValue::money($step->open()),
        ];
    }
}
