<?php

namespace Picqer\BolRetailerV10\OpenApi;

class SpecNormalizer
{
    private array $documents = [];

    private array $outputSchemas = [];

    private array $schemaNameMap = [];

    private array $reservedTypes = [];

    private array $allocatedTypes = [];

    public function normalize(string $source, string $collisionPrefix, array $reservedSchemaNames = []): array
    {
        $this->documents = [];
        $this->outputSchemas = [];
        $this->schemaNameMap = [];
        $this->reservedTypes = array_fill_keys(array_map([$this, 'getGeneratedType'], $reservedSchemaNames), true);
        $this->allocatedTypes = [];

        $document = $this->loadDocument($source);

        $paths = [];
        foreach ($document['paths'] ?? [] as $path => $methods) {
            $paths[$path] = [];
            foreach ($methods as $httpMethod => $definition) {
                $paths[$path][$httpMethod] = $this->normalizeOperation($definition, $source, $collisionPrefix);
            }
        }

        foreach ($document['components']['schemas'] ?? [] as $schemaName => $schema) {
            if (! $this->isComplexSchema($schema)) {
                continue;
            }

            $this->ensureSchemaComponent($source, $schemaName, $schema, $collisionPrefix);
        }

        return [
            'openapi' => $document['openapi'] ?? '3.0.1',
            'info' => $document['info'] ?? [],
            'paths' => $paths,
            'components' => [
                'schemas' => $this->outputSchemas,
            ],
        ];
    }

    private function normalizeOperation(array $definition, string $documentUri, string $collisionPrefix): array
    {
        $normalized = $definition;

        if (isset($normalized['parameters'])) {
            $normalized['parameters'] = array_map(function (array $parameter) use ($documentUri, $collisionPrefix) {
                return $this->normalizeParameter($parameter, $documentUri, $collisionPrefix);
            }, $normalized['parameters']);
        }

        if (isset($normalized['requestBody'])) {
            $normalized['requestBody'] = $this->normalizeRequestBody($normalized['requestBody'], $documentUri, $collisionPrefix);
        }

        if (isset($normalized['responses'])) {
            foreach ($normalized['responses'] as $statusCode => $response) {
                if (in_array((string) $statusCode, ['200', '202', '207'], true)) {
                    $normalized['responses'][$statusCode] = $this->normalizeResponse($response, $documentUri, $collisionPrefix);
                }
            }
        }

        return $normalized;
    }

    private function normalizeParameter(array $parameter, string $documentUri, string $collisionPrefix): array
    {
        if (isset($parameter['$ref'])) {
            $parameter = $this->resolveReference($parameter['$ref'], $documentUri);
        }

        if (isset($parameter['schema'])) {
            $parameter['schema'] = $this->normalizeSchemaReference($parameter['schema'], $documentUri, $collisionPrefix);
        }

        return $parameter;
    }

    private function normalizeRequestBody(array $requestBody, string $documentUri, string $collisionPrefix): array
    {
        if (isset($requestBody['$ref'])) {
            $requestBody = $this->resolveReference($requestBody['$ref'], $documentUri);
        }

        foreach ($requestBody['content'] ?? [] as $contentType => $contentDefinition) {
            if (! isset($contentDefinition['schema'])) {
                continue;
            }

            $requestBody['content'][$contentType]['schema'] = $this->normalizeSchemaReference(
                $contentDefinition['schema'],
                $documentUri,
                $collisionPrefix
            );
        }

        return $requestBody;
    }

    private function normalizeResponse(array $response, string $documentUri, string $collisionPrefix): array
    {
        if (isset($response['$ref'])) {
            $response = $this->resolveReference($response['$ref'], $documentUri);
        }

        foreach ($response['content'] ?? [] as $contentType => $contentDefinition) {
            if (! isset($contentDefinition['schema'])) {
                continue;
            }

            $response['content'][$contentType]['schema'] = $this->normalizeSchemaReference(
                $contentDefinition['schema'],
                $documentUri,
                $collisionPrefix
            );
        }

        return $response;
    }

    private function normalizeSchema(array $schema, string $documentUri, string $collisionPrefix): array
    {
        $normalized = $schema;

        if (isset($normalized['properties'])) {
            foreach ($normalized['properties'] as $propertyName => $propertySchema) {
                $normalized['properties'][$propertyName] = $this->normalizeSchemaReference(
                    $propertySchema,
                    $documentUri,
                    $collisionPrefix
                );
            }
        }

        if (isset($normalized['items'])) {
            $normalized['items'] = $this->normalizeSchemaReference($normalized['items'], $documentUri, $collisionPrefix);
        }

        return $normalized;
    }

    private function normalizeSchemaReference(array $schema, string $documentUri, string $collisionPrefix): array
    {
        if (isset($schema['$ref'])) {
            [$resolvedDocumentUri, $targetSchemaName, $targetSchema] = $this->resolveSchemaReference($schema['$ref'], $documentUri);

            if ($this->isComplexSchema($targetSchema)) {
                $localSchemaName = $this->ensureSchemaComponent(
                    $resolvedDocumentUri,
                    $targetSchemaName,
                    $targetSchema,
                    $collisionPrefix
                );

                return [
                    '$ref' => '#/components/schemas/' . $localSchemaName,
                ];
            }

            return $this->normalizeSchema($targetSchema, $resolvedDocumentUri, $collisionPrefix);
        }

        return $this->normalizeSchema($schema, $documentUri, $collisionPrefix);
    }

    private function ensureSchemaComponent(
        string $documentUri,
        string $schemaName,
        array $schema,
        string $collisionPrefix
    ): string {
        $schemaReference = $documentUri . '#/components/schemas/' . $schemaName;

        if (isset($this->schemaNameMap[$schemaReference])) {
            return $this->schemaNameMap[$schemaReference];
        }

        $localSchemaName = $this->allocateSchemaName($schemaName, $collisionPrefix);
        $this->schemaNameMap[$schemaReference] = $localSchemaName;
        $this->outputSchemas[$localSchemaName] = [];

        $this->outputSchemas[$localSchemaName] = $this->normalizeSchema($schema, $documentUri, $collisionPrefix);

        return $localSchemaName;
    }

    private function allocateSchemaName(string $schemaName, string $collisionPrefix): string
    {
        $candidate = $schemaName;
        $candidateType = $this->getGeneratedType($candidate);

        if (isset($this->reservedTypes[$candidateType]) || isset($this->allocatedTypes[$candidateType])) {
            $candidate = $collisionPrefix . $this->getGeneratedType($schemaName);
            $candidateType = $this->getGeneratedType($candidate);
        }

        $suffix = 2;
        while (isset($this->reservedTypes[$candidateType]) || isset($this->allocatedTypes[$candidateType])) {
            $candidate = $collisionPrefix . $this->getGeneratedType($schemaName) . $suffix;
            $candidateType = $this->getGeneratedType($candidate);
            $suffix++;
        }

        $this->allocatedTypes[$candidateType] = true;

        return $candidate;
    }

    private function resolveSchemaReference(string $reference, string $documentUri): array
    {
        [$resolvedDocumentUri, $pointer] = $this->resolveReferenceTarget($reference, $documentUri);
        $schema = $this->resolveJsonPointer($this->loadDocument($resolvedDocumentUri), $pointer);

        return [
            $resolvedDocumentUri,
            basename($pointer),
            $schema,
        ];
    }

    private function resolveReference(string $reference, string $documentUri): array
    {
        [$resolvedDocumentUri, $pointer] = $this->resolveReferenceTarget($reference, $documentUri);

        return $this->resolveJsonPointer($this->loadDocument($resolvedDocumentUri), $pointer);
    }

    private function resolveReferenceTarget(string $reference, string $documentUri): array
    {
        [$relativePath, $pointer] = array_pad(explode('#', $reference, 2), 2, '');
        $resolvedDocumentUri = $relativePath === ''
            ? $documentUri
            : $this->resolveUri($documentUri, $relativePath);

        return [
            $resolvedDocumentUri,
            '#' . $pointer,
        ];
    }

    private function resolveJsonPointer(array $document, string $pointer): array
    {
        $segments = array_filter(explode('/', ltrim($pointer, '#/')), 'strlen');
        $resolved = $document;

        foreach ($segments as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
            $resolved = $resolved[$segment];
        }

        return $resolved;
    }

    private function loadDocument(string $uri): array
    {
        if (isset($this->documents[$uri])) {
            return $this->documents[$uri];
        }

        $contents = file_get_contents($uri);
        $extension = strtolower(pathinfo(parse_url($uri, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));

        if ($extension === 'json') {
            $document = json_decode($contents, true);
        } else {
            $document = yaml_parse($contents);
        }

        $this->documents[$uri] = $document;

        return $document;
    }

    private function isComplexSchema(array $schema): bool
    {
        return isset($schema['properties']) || (($schema['type'] ?? null) === 'object');
    }

    private function getGeneratedType(string $schemaName): string
    {
        $type = str_replace(['.', ','], '', $schemaName);
        $words = explode(' ', $type);
        $words = array_map(function (string $word): string {
            return ucfirst($word);
        }, $words);
        $type = implode('', $words);

        if ($type === 'Return') {
            $type = 'ReturnObject';
        }

        return $type;
    }

    private function resolveUri(string $baseUri, string $relativePath): string
    {
        if (parse_url($relativePath, PHP_URL_SCHEME) !== null) {
            return $relativePath;
        }

        $parts = parse_url($baseUri);
        $basePath = $parts['path'] ?? '/';
        $directory = preg_replace('#/[^/]*$#', '/', $basePath);
        $path = $directory . $relativePath;

        $normalizedPath = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($normalizedPath);
                continue;
            }

            $normalizedPath[] = $segment;
        }

        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return sprintf(
            '%s://%s%s/%s',
            $parts['scheme'],
            $parts['host'],
            $port,
            implode('/', $normalizedPath)
        );
    }
}
