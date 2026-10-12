<?php
/**
 * AGGREGATE plugin for Grav CMS.
 *
 * Adds AGGREGATE analytics to public HTML pages. AGGREGATE is a
 * self-hosted, privacy-first analytics platform; this plugin emits script
 * tags pointing at the site owner's own AGGREGATE instance: the tracker
 * directly, or the tag manager lite container, optionally preceded by one
 * hosted consent banner. It stores nothing itself and sets no cookies.
 *
 * @license MIT
 */

namespace Grav\Plugin;

use Grav\Common\Plugin;

class AggregatePlugin extends Plugin
{
    /**
     * Subscribe to the plugin initialization event; rendering events are
     * enabled from there so the scripts are never wired up for Grav Admin.
     *
     * @return array
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
        ];
    }

    public function onPluginsInitialized(): void
    {
        if ($this->isAdmin()) {
            $this->exposeSiteId();
            return;
        }

        $this->enable([
            'onOutputGenerated' => ['onOutputGenerated', 0],
        ]);
    }

    /**
     * Make the derived tag manager site ID available to the Admin
     * configuration form as a read-only value. The ID is the first 24 hex
     * characters of the SHA-256 of the website token (the same derivation
     * AGGREGATE uses; see app:tag-manager:sites). It lives only in the
     * runtime configuration object and is never read back by this plugin:
     * the script URLs always derive it from the token directly.
     */
    private function exposeSiteId(): void
    {
        $token = trim((string) $this->config->get('plugins.aggregate.website_token'));
        $this->config->set(
            'plugins.aggregate.site_id',
            $token === '' ? '' : self::siteId($token)
        );
    }

    private static function siteId(string $token): string
    {
        return substr(hash('sha256', $token), 0, 24);
    }

    /**
     * Insert the AGGREGATE script tags immediately before </body> of the
     * generated HTML output. Output without a </body> (feeds, JSON,
     * redirects) is left untouched.
     */
    public function onOutputGenerated(): void
    {
        $tags = $this->buildScriptTags();
        if ($tags === null) {
            return;
        }

        $output = (string) $this->grav->output;
        $position = strripos($output, '</body>');
        if ($position === false) {
            return;
        }

        $this->grav->output = substr_replace($output, $tags . "\n</body>", $position, 7);
    }

    /**
     * Build the script tags for this response from the plugin
     * configuration, or return null when nothing must appear: plugin
     * disabled, missing instance URL or website token, admin context, a
     * Do Not Track request we are configured to respect, or a visitor who
     * can log in to Grav Admin while admin exclusion is on. These gates
     * apply to both installation modes.
     *
     * Tag order follows AGGREGATE's documented snippets (docs/SETUP.md):
     * the consent banner, when one is selected, loads before the tracker
     * or the tag container so a stored choice is in place first. Only one
     * banner is ever emitted; the setting is a single choice, not toggles.
     */
    private function buildScriptTags(): ?string
    {
        if ($this->isAdmin()) {
            return null;
        }

        $config = $this->config->get('plugins.aggregate');
        if (!\is_array($config) || empty($config['enabled'])) {
            return null;
        }

        $instance = isset($config['instance_url']) ? rtrim(trim((string) $config['instance_url']), '/') : '';
        $token = isset($config['website_token']) ? trim((string) $config['website_token']) : '';
        if ($instance === '' || $token === '') {
            return null;
        }

        // Respect Do Not Track: none of the scripts are loaded at all.
        if (!empty($config['respect_dnt']) && ($_SERVER['HTTP_DNT'] ?? '') === '1') {
            return null;
        }

        // Optionally keep users with Grav Admin access out of the statistics.
        if (!empty($config['exclude_admins']) && isset($this->grav['user'])) {
            $user = $this->grav['user'];
            if ($user && ($user->authorize('admin.login') || $user->authorize('admin.super'))) {
                return null;
            }
        }

        $min = !empty($config['minified']) ? '?min=1' : '';
        $siteId = self::siteId($token);
        $tags = [];

        // The hosted consent banner, when selected, comes first in both
        // modes. The built-in consent manager lite works alongside the
        // plain tracker as well as the tag container: Setup offers the
        // consent choice separately from the installation method, and its
        // direct-tracker snippet loads cmp-lite before aggregate.js.
        $banner = isset($config['consent_banner']) ? (string) $config['consent_banner'] : 'none';
        if ($banner === 'builtin') {
            $tags[] = $this->scriptTag($instance . '/cmp-lite/sites/' . $siteId . '/consent.js' . $min);
        } elseif ($banner === 'standalone') {
            $tags[] = $this->scriptTag($instance . '/standalone-cmp/sites/' . $siteId . '/consent.js' . $min);
        }

        $mode = isset($config['installation_mode']) ? (string) $config['installation_mode'] : 'tracker';
        if ($mode === 'tagmanager') {
            // Tag manager lite: the hosted container only. The tracker
            // never appears here; it runs as a tag inside the container,
            // configured in AGGREGATE itself.
            $tags[] = $this->scriptTag($instance . '/tms-lite/sites/' . $siteId . '/lib.js' . $min);
        } else {
            $tags[] = $this->buildTrackerTag($config, $instance, $token);
        }

        return implode("\n", $tags);
    }

    private function scriptTag(string $src): string
    {
        return '<script src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '"'
            . ' defer referrerpolicy="no-referrer"></script>';
    }

    /**
     * The v1.0 tracker tag: configuration travels in the script URL
     * (documented URL-parameter form), so the tag needs no inline
     * JavaScript and stays friendly to strict Content Security Policies.
     */
    private function buildTrackerTag(array $config, string $instance, string $token): string
    {
        $params = [];
        if (!empty($config['minified'])) {
            $params['min'] = '1';
        }
        $params['endpoint'] = $instance . '/api/receive';
        $params['token'] = $token;

        // Tracker mode only: in tag manager mode the tracker tag's own
        // URL inside AGGREGATE carries its consent default instead.
        $consent = isset($config['consent_default']) ? (string) $config['consent_default'] : 'denied';
        if ($consent === 'denied') {
            $params['consent'] = '0';
        } elseif ($consent === 'granted') {
            $params['consent'] = '1';
        }

        $src = $instance . '/aggregate.js?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $strict = !empty($config['strict_profile']) ? ' data-collection-profile="strict"' : '';

        return '<script src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '"'
            . $strict
            . ' defer referrerpolicy="no-referrer"></script>';
    }
}
