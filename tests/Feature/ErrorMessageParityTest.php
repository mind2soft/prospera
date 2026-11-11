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
 */
class ErrorMessageParityTest extends TestCase
{
    /**
     * The status → `common.errors.*` key table of `statusMessage()`.
     *
     * @var array<int, string>
     */
    private const CLIENT_KEYS = [
        401 => 'unauthenticated',
        403 => 'forbidden',
        404 => 'notFound',
        419 => 'sessionExpired',
        429 => 'tooMany',
        503 => 'unavailable',
        500 => 'server',
        400 => 'generic',
    ];

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
        $path = resource_path("js/locales/{$locale}/common.json");
        $this->assertFileExists($path);

        /** @var array{common: array{errors: array<string, string>}} $catalog */
        $catalog = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $client = $catalog['common']['errors'];

        App::setLocale($locale);

        foreach (self::CLIENT_KEYS as $status => $key) {
            $this->assertArrayHasKey($key, $client, "common.errors.{$key} is missing ({$locale})");
            $this->assertSame(
                LocalizedJsonErrors::messageFor($status),
                $client[$key],
                "common.errors.{$key} differs from the server's {$status} message ({$locale})",
            );
        }
    }

    public function test_the_key_table_mirrors_the_client_status_mapping(): void
    {
        $source = (string) file_get_contents(resource_path('js/lib/api.ts'));

        preg_match_all(
            "/case status === (\\d+):\\s*return t\\('common\\.errors\\.(\\w+)'\\)/",
            $source,
            $matches,
        );
        $exact = array_combine(array_map('intval', $matches[1]), $matches[2]);

        $this->assertNotEmpty($exact, 'statusMessage() could not be parsed');
        $this->assertSame(
            array_diff_key(self::CLIENT_KEYS, [500 => true, 400 => true]),
            $exact,
            "statusMessage()'s exact statuses changed: update this test's table",
        );
        $this->assertMatchesRegularExpression(
            "/case status >= 500:\\s*return t\\('common\\.errors\\.server'\\)/",
            $source,
        );
        $this->assertMatchesRegularExpression(
            "/default:\\s*return t\\('common\\.errors\\.generic'\\)/",
            $source,
        );
    }
}
