<?php

namespace Picqer\BolRetailerV10\OpenApi;

/**
 * Shared helper for the code generators to flatten a schema property definition
 * of the shape `{ "allOf": [{ "$ref": "..." }] }` into a plain `{ "$ref": "..." }`.
 *
 * OpenAPI 3 registry specs (e.g. economic-operators) frequently wrap a single
 * `$ref` in an `allOf` to attach a description alongside the reference. The
 * generators only understand direct `$ref` properties, so this helper unwraps
 * that trivial case. Other schema shapes are returned unchanged.
 */
trait SchemaPropertyNormalizer
{
    protected function normalizeSchemaProperty(array $schema): array
    {
        if (isset($schema['allOf'][0]['$ref']) && count($schema['allOf']) === 1) {
            return [
                '$ref' => $schema['allOf'][0]['$ref'],
            ];
        }

        return $schema;
    }
}
