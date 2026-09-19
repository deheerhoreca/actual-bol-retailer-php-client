<?php

namespace Picqer\BolRetailerV10\Tests\OpenApi;

use PHPUnit\Framework\TestCase;
use Picqer\BolRetailerV10\OpenApi\ClientGenerator;

class ClientGeneratorTest extends TestCase
{
    public function testMixedNoContentResponseMakesNonCollectionPropertyNullable(): void
    {
        $generator = new class () extends ClientGenerator {
            private array $returnTypeOverride = [];

            public function __construct()
            {
            }

            public function generateMethodForTest(array $responses, array $returnType): string
            {
                $this->returnTypeOverride = $returnType;
                $this->specs = [
                    'paths' => [
                        '/test' => [
                            'get' => [
                                'operationId' => 'get-test',
                                'summary' => 'Get test',
                                'responses' => $responses,
                            ],
                        ],
                    ],
                ];

                $code = [];
                $this->generateMethod('/test', 'get', $code);

                return implode("\n", $code);
            }

            protected function getReturnType(array $responses): array
            {
                return $this->returnTypeOverride;
            }
        };

        $code = $generator->generateMethodForTest([
            '200' => [
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                        ],
                    ],
                ],
            ],
            '204' => [
                'description' => 'No content',
            ],
        ], [
            'doc' => 'string',
            'php' => 'string',
            'property' => 'value',
        ]);

        $this->assertStringContainsString('public function getTest(): ?string', $code);
        $this->assertStringContainsString('return $result === null ? null : $result->value;', $code);
        $this->assertStringNotContainsString('return $result === null ? [] : $result->value;', $code);
    }
}
