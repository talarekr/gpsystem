<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class AdminNavigationIconRuleTest extends TestCase
{
    public function test_icon_enabled_filament_navigation_groups_have_iconless_children(): void
    {
        $iconEnabledGroups = $this->iconEnabledGroups();

        $this->assertNotEmpty($iconEnabledGroups, 'Expected at least one icon-enabled Filament navigation group to guard.');

        foreach ($this->filamentPhpFiles() as $file) {
            $contents = file_get_contents($file->getPathname());

            $childGroups = $this->childNavigationGroups($contents);
            $guardedGroups = array_intersect($childGroups, $iconEnabledGroups);

            if ($guardedGroups === []) {
                continue;
            }

            $relativePath = $this->relativePath($file);

            $this->assertDoesNotMatchRegularExpression(
                '/protected\\s+static\\s+\\?string\\s+\$navigationIcon\\s*=\\s*[\'"][^\'"]+[\'"]/',
                $contents,
                sprintf(
                    '%s belongs to icon-enabled navigation group(s) [%s], so the child item must not configure a navigation icon.',
                    $relativePath,
                    implode(', ', $guardedGroups),
                ),
            );

            $this->assertDoesNotMatchRegularExpression(
                '/NavigationItem::make\([^;]+->group\([^;]+->icon\(/s',
                $contents,
                sprintf(
                    '%s defines a custom NavigationItem inside icon-enabled navigation group(s) [%s], so the child item must not call ->icon().',
                    $relativePath,
                    implode(', ', $guardedGroups),
                ),
            );
        }
    }

    /**
     * @return array<int, string>
     */
    private function iconEnabledGroups(): array
    {
        $provider = file_get_contents(__DIR__.'/../../app/Providers/Filament/AdminPanelProvider.php');

        preg_match_all(
            "/NavigationGroup::make\\(['\"]([^'\"]+)['\"]\\)(?:(?!NavigationGroup::make).)*->icon\\(/s",
            $provider,
            $matches,
        );

        return array_values(array_unique($matches[1]));
    }

    /**
     * @return array<int, string>
     */
    private function childNavigationGroups(string $contents): array
    {
        preg_match_all(
            '/protected\\s+static\\s+\\?string\\s+\$navigationGroup\\s*=\\s*[\'"]([^\'"]+)[\'"]/',
            $contents,
            $propertyMatches,
        );

        preg_match_all(
            "/->group\\(\\s*['\"]([^'\"]+)['\"]\\s*\\)/",
            $contents,
            $customItemMatches,
        );

        return array_values(array_unique([
            ...$propertyMatches[1],
            ...$customItemMatches[1],
        ]));
    }

    /**
     * @return iterable<SplFileInfo>
     */
    private function filamentPhpFiles(): iterable
    {
        $directory = new RecursiveDirectoryIterator(__DIR__.'/../../app/Filament');
        $files = new RecursiveIteratorIterator($directory);

        foreach ($files as $file) {
            if (! $file instanceof SplFileInfo || $file->isDir() || $file->getExtension() !== 'php') {
                continue;
            }

            yield $file;
        }
    }

    private function relativePath(SplFileInfo $file): string
    {
        return substr($file->getPathname(), strlen(dirname(__DIR__, 2)) + 1);
    }
}
