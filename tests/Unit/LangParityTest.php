<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Every server-side locale carries the same translation keys as English,
 * with the same placeholders (docs/localization.md).
 */
class LangParityTest extends TestCase
{
    private const LANG = __DIR__.'/../../lang';

    /**
     * Attribute names are optional per locale: English falls back to the
     * field name ("start_date" -> "start date").
     */
    private const OPTIONAL_KEYS = ['validation.attributes'];

    /**
     * Messages translated at the call site from a variable (__($error)),
     * invisible to the literal scan below.
     */
    private const DYNAMIC_STRINGS = [
        // FinancialProfileInvite::usageError() and the invite accept flows.
        'This invite has expired.',
        'This invite is invalid.',
        'This invite was issued to another email address.',
        'You are already a member of this profile.',
        // The verified middleware's 403, translated by LocalizedJsonErrors.
        'Your email address is not verified.',
    ];

    /**
     * @return array<string, array{string}>
     */
    public static function locales(): array
    {
        $locales = [];

        foreach (glob(self::LANG.'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $locale = basename($dir);

            if ($locale !== 'en') {
                $locales[$locale] = [$locale];
            }
        }

        return $locales;
    }

    #[DataProvider('locales')]
    public function test_php_lang_files_have_the_english_keys(string $locale): void
    {
        $english = $this->phpLines('en');
        $translated = $this->phpLines($locale);

        $this->assertSame(
            array_keys($english),
            array_keys(array_intersect_key($translated, $english)),
            "lang/{$locale} is missing keys of lang/en.",
        );
        $this->assertSame([], array_keys(array_diff_key($translated, $english)), "lang/{$locale} has keys lang/en does not.");

        foreach ($english as $key => $line) {
            $this->assertSame($this->placeholders($line), $this->placeholders($translated[$key]), "Placeholders differ for {$locale}: {$key}.");
        }
    }

    #[DataProvider('locales')]
    public function test_the_json_catalog_translates_every_app_string(string $locale): void
    {
        $catalog = $this->jsonLines($locale);

        $missing = array_values(array_diff($this->appStrings(), array_keys($catalog)));

        $this->assertSame([], $missing, "lang/{$locale}.json lacks translations.");

        foreach ($catalog as $key => $line) {
            $this->assertNotSame('', trim($line), "Empty translation for {$locale}: {$key}.");
            $this->assertSame($this->placeholders($key), $this->placeholders($line), "Placeholders differ for {$locale}: {$key}.");
        }
    }

    /**
     * Flattened "file.key.path" => line, optional keys excluded.
     *
     * @return array<string, string>
     */
    private function phpLines(string $locale): array
    {
        $lines = [];

        foreach (glob(self::LANG."/{$locale}/*.php") ?: [] as $file) {
            $this->flatten(require $file, basename($file, '.php'), $lines);
        }

        ksort($lines);

        return $lines;
    }

    /**
     * @param  array<mixed>  $array
     * @param  array<string, string>  $lines
     */
    private function flatten(array $array, string $prefix, array &$lines): void
    {
        if (in_array($prefix, self::OPTIONAL_KEYS, true)) {
            return;
        }

        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $this->flatten($value, "{$prefix}.{$key}", $lines);
            } else {
                $lines["{$prefix}.{$key}"] = (string) $value;
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function jsonLines(string $locale): array
    {
        $path = self::LANG."/{$locale}.json";
        $this->assertFileExists($path);

        $lines = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($lines);

        return $lines;
    }

    /**
     * Literal strings passed to __() in app/ that are not lang-file keys
     * ("mail.invite.subject").
     *
     * @return list<string>
     */
    private function appStrings(): array
    {
        $strings = self::DYNAMIC_STRINGS;
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../app'));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            preg_match_all("/__\(\s*'((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents($file->getPathname()), $matches);

            foreach ($matches[1] as $literal) {
                $string = stripcslashes($literal);

                if (! preg_match('/^[a-z_]+(\.[a-z_]+)+$/', $string)) {
                    $strings[] = $string;
                }
            }
        }

        $strings = array_values(array_unique($strings));
        sort($strings);

        $this->assertNotEmpty($strings);

        return $strings;
    }

    /**
     * @return list<string>
     */
    private function placeholders(string $line): array
    {
        preg_match_all('/:([a-zA-Z_]+)/', $line, $matches);
        $names = array_values(array_unique($matches[1]));
        sort($names);

        return $names;
    }
}
