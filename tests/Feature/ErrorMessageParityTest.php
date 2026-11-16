<?php

namespace Tests\Feature;

use App\Exceptions\LocalizedJsonErrors;
use Illuminate\Support\Facades\App;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The client's per-status fallback messages (`statusMessage()` in
 * resources/js/lib/api.ts → `common.errors.*`) are the server's
 * (LocalizedJsonErrors::messageFor()) word for word, in every locale: the
 * fallback only shows when a response carries no message, and must not
 * read differently from the usual one (docs/localization.md).
 *
 * Both sides map statuses from the shared
 * `resources/js/locales/status-error-keys.json` source, which this test
 * consumes directly. The client's own reading of that source — the exact
 * statuses, the ≥ 500 catch-all and the default — is exercised by the
 * Vitest suite (resources/js/lib/statusMessage.test.ts).
 */
class ErrorMessageParityTest extends TestCase
{
    /**
     * The shared status → `common.errors.*` key mapping: the statuses the
     * client (and the server) single out, then the catch-all keys for
     * server errors (status ≥ 500) and for every other status.
     */
    private function statusErrorKeys(): array
    {
        $path = resource_path('js/locales/status-error-keys.json');
        $this->assertFileExists($path);

        /** @var mixed $map */
        $map = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($map);
        $this->assertArrayHasKey('exact', $map);
        $this->assertArrayHasKey('serverErrors', $map);
        $this->assertArrayHasKey('fallback', $map);
        $this->assertNotEmpty($map['exact'], 'The shared status mapping has no exact statuses');
        $this->assertIsString($map['serverErrors']);
        $this->assertIsString($map['fallback']);

        foreach ($map['exact'] as $status => $key) {
            $this->assertIsString($key);
            $this->assertNotSame('', $key, "The key of status {$status} is empty");
            $this->assertMatchesRegularExpression('/^\d{3}$/', (string) $status);
        }

        /** @var array{exact: array<string, string>, serverErrors: string, fallback: string} $map */
        return $map;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function locales(): array
    {
        return ['en' => ['en'], 'fr' => ['fr']];
    }

    #[DataProvider('locales')]
    public function test_the_client_fallback_messages_match_the_server_ones(string $locale): void
    {
        $map = $this->statusErrorKeys();

        $path = resource_path("js/locales/{$locale}/common.json");
        $this->assertFileExists($path);

        /** @var array{common: array{errors: array<string, string>}} $catalog */
        $catalog = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $client = $catalog['common']['errors'];

        App::setLocale($locale);

        // The statuses both sides single out, plus one representative of
        // each catch-all: a server error (≥ 500) and an unmapped status.
        $statuses = $map['exact'];
        $statuses['500'] = $map['serverErrors'];
        $statuses['400'] = $map['fallback'];

        foreach ($statuses as $status => $key) {
            $this->assertArrayHasKey($key, $client, "common.errors.{$key} is missing ({$locale})");
            $this->assertSame(
                LocalizedJsonErrors::messageFor((int) $status),
                $client[$key],
                "common.errors.{$key} differs from the server's {$status} message ({$locale})",
            );
        }
    }
}
