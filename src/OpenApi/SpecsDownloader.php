<?php

namespace Picqer\BolRetailerV10\OpenApi;

class SpecsDownloader
{
    private const SPECS = [
        [
            'source' => 'https://api.bol.com/retailer/public/apispec/Retailer%20API%20-%20v10',
            'target' => 'retailer.json',
            'format' => 'json',
        ],
        [
            'source' => 'https://api.bol.com/retailer/public/apispec/Shared%20API%20-%20v10',
            'target' => 'shared.json',
            'format' => 'json',
        ],
        [
            'source' => 'https://api.bol.com/registry/api-definitions/economic-operators/economic-operators-v1.yaml',
            'target' => 'economic-operators.json',
            'format' => 'yaml',
            'collisionPrefix' => 'EconomicOperators',
        ],
        [
            'source' => 'https://api.bol.com/registry/api-definitions/delivery-promise/delivery-promise-v1.yaml',
            'target' => 'delivery-promise.json',
            'format' => 'yaml',
            'collisionPrefix' => 'DeliveryPromise',
        ],
    ];

    public static function run(): void
    {
        $reservedSchemaNames = [];

        foreach (static::SPECS as $spec) {
            $sourceFile = file_get_contents($spec['source']);

            if (($spec['format'] ?? 'json') === 'json') {
                $specContents = json_decode($sourceFile, true);
            } else {
                $specContents = (new SpecNormalizer())->normalize(
                    $spec['source'],
                    $spec['collisionPrefix'],
                    $reservedSchemaNames
                );
            }

            // Tidy JSON formatting
            $sourceTidied = json_encode($specContents, JSON_PRETTY_PRINT + JSON_UNESCAPED_SLASHES + JSON_UNESCAPED_UNICODE);

            file_put_contents(__DIR__ . DIRECTORY_SEPARATOR . $spec['target'], $sourceTidied);

            foreach (array_keys($specContents['components']['schemas'] ?? []) as $schemaName) {
                $reservedSchemaNames[] = $schemaName;
            }
        }
    }
}
