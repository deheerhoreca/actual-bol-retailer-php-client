<?php

namespace Picqer\BolRetailerV10\OpenApi;

class SwaggerSpecs
{
    private $specs = [];

    public function __construct($specs = [])
    {
        $this->specs = $specs;
    }

    public function load(string $file): SwaggerSpecs
    {
        $content = file_get_contents($file);
        $content = $this->replaceErroneousCharacters($content);

        $this->specs = json_decode($content, true);

        return $this;
    }

    private function replaceErroneousCharacters(string $content): string
    {
        $replacements = [
            hex2bin('e28082') => ' ', // 'ENSP' space
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $content);
    }

    public function getSpecs(): array
    {
        return $this->specs;
    }

    public function merge(SwaggerSpecs $specs): SwaggerSpecs
    {
        $resultSpecs = $this->specs;
        $otherSpecs = $specs->getSpecs();

        foreach ($otherSpecs['paths'] as $path => $methods) {
            foreach ($methods as $httpMethod => $definition) {
                $targetPath = $path;

                if (isset($resultSpecs['paths'][$path][$httpMethod])) {
                    // The same path + HTTP method exists in an earlier merged spec (e.g. v10 and
                    // v11 Offers API operations that share a URL but use different content types).
                    // Keep both by storing the new operation under an aliased path key; the
                    // generators strip everything from the '#' when building the request URL.
                    $targetPath = $path . '#' . ($definition['operationId'] ?? $httpMethod);
                }

                $resultSpecs['paths'][$targetPath][$httpMethod] = $definition;
            }
        }

        $resultSpecs['components']['schemas'] = array_merge($resultSpecs['components']['schemas'], $otherSpecs['components']['schemas']);

        return new SwaggerSpecs($resultSpecs);
    }
}
