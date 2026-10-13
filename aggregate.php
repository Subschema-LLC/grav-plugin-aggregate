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
use RocketTheme\Toolbox\Event\Event;

class AggregatePlugin extends Plugin
{
    /**
     * Subscribe to the plugin initialization event; rendering events are
     * enabled from there so the scripts are never wired up for Grav Admin.
     *
     * The two settings-form events are subscribed here rather than from
     * onPluginsInitialized: under Grav 2's Admin (admin2 and the API plugin)
     * isAdmin() is still false when plugins initialize, so a listener gated
     * on it would never run there.
     *
     * @return array
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
            'onApiBlueprintResolved' => ['onApiBlueprintResolved', 0],
            'onAdminSave' => ['onAdminSave', 0],
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
     * Classic Grav Admin: make the derived tag manager site ID available to
     * the configuration form, which reads the runtime configuration. The ID
     * is the first 24 hex characters of the SHA-256 of the website token (the
     * same derivation AGGREGATE uses; see app:tag-manager:sites). The script
     * URLs never read it back: they always derive it from the token directly.
     */
    private function exposeSiteId(): void
    {
        $this->config->set(
            'plugins.aggregate.site_id',
            self::siteIdFor($this->config->get('plugins.aggregate.website_token'))
        );
    }

    /**
     * Grav 2 Admin: the form's values come from the YAML files, not from the
     * runtime configuration, and the token reaches the browser masked, so
     * exposeSiteId() cannot reach it and the browser cannot derive the ID.
     * Give the read-only field the ID derived from the saved token as its
     * default, which the form shows while no site ID has been saved.
     */
    public function onApiBlueprintResolved(Event $event): void
    {
        if (($event['context'] ?? null) !== 'plugin' || ($event['plugin'] ?? null) !== 'aggregate') {
            return;
        }

        $fields = $event['fields'] ?? null;
        if (!\is_array($fields)) {
            return;
        }

        $event['fields'] = self::withSiteIdDefault(
            $fields,
            self::siteIdFor($this->config->get('plugins.aggregate.website_token'))
        );
    }

    /**
     * Both Admins: refresh the site ID from the token being saved, so the form
     * shows the right ID as soon as a new token is saved (Grav 2 Admin reloads
     * the saved values, not the form definition, after saving). It is kept
     * for display only; the script URLs always derive it from the token.
     */
    public function onAdminSave(Event $event): void
    {
        $object = $event['object'] ?? null;
        if (!\is_object($object) || !method_exists($object, 'get') || !method_exists($object, 'set')
            || !$this->isOwnConfiguration($object)) {
            return;
        }

        $object->set('site_id', self::siteIdFor($object->get('website_token')));
    }

    /**
     * Whether a saved object is this plugin's configuration: its storage file
     * is plugins/aggregate.yaml (classic Admin and the Grav 2 API both attach
     * it), or, when no file is attached, the Admin route is this plugin's.
     */
    private function isOwnConfiguration(object $object): bool
    {
        $file = method_exists($object, 'file') ? $object->file() : null;
        if (\is_object($file) && method_exists($file, 'filename')) {
            $filename = str_replace('\\', '/', (string) $file->filename());
            $suffix = '/plugins/aggregate.yaml';

            return substr($filename, -\strlen($suffix)) === $suffix;
        }

        $admin = $this->grav['admin'] ?? null;
        $route = \is_object($admin) && isset($admin->route) ? (string) $admin->route : '';

        return trim($route, '/') === 'plugins/aggregate';
    }

    /**
     * Set the default of the site_id field, wherever it sits in the
     * serialized field tree.
     *
     * @param array<int|string, mixed> $fields
     * @return array<int|string, mixed>
     */
    private static function withSiteIdDefault(array $fields, string $siteId): array
    {
        foreach ($fields as $key => $field) {
            if (!\is_array($field)) {
                continue;
            }
            if (($field['name'] ?? null) === 'site_id') {
                $field['default'] = $siteId;
            }
            if (isset($field['fields']) && \is_array($field['fields'])) {
                $field['fields'] = self::withSiteIdDefault($field['fields'], $siteId);
            }
            $fields[$key] = $field;
        }

        return $fields;
    }

    /**
     * The site ID for a website token, or an empty string without one.
     *
     * @param mixed $token
     */
    private static function siteIdFor($token): string
    {
        $token = \is_string($token) ? trim($token) : '';

        return $token === '' ? '' : self::siteId($token);
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
