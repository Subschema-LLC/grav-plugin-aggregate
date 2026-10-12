<?php
/**
 * AGGREGATE plugin for Grav CMS.
 *
 * Adds the AGGREGATE analytics tracker to public HTML pages. AGGREGATE is a
 * self-hosted, privacy-first analytics platform; this plugin emits a single
 * script tag pointing at the site owner's own AGGREGATE instance. It stores
 * nothing itself and sets no cookies.
 *
 * @license MIT
 */

namespace Grav\Plugin;

use Grav\Common\Plugin;

class AggregatePlugin extends Plugin
{
    /**
     * Subscribe to the plugin initialization event; rendering events are
     * enabled from there so the tracker is never wired up for Grav Admin.
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
            return;
        }

        $this->enable([
            'onOutputGenerated' => ['onOutputGenerated', 0],
        ]);
    }

    /**
     * Insert the tracker tag immediately before </body> of the generated
     * HTML output. Output without a </body> (feeds, JSON, redirects) is
     * left untouched.
     */
    public function onOutputGenerated(): void
    {
        $tag = $this->buildTrackerTag();
        if ($tag === null) {
            return;
        }

        $output = (string) $this->grav->output;
        $position = strripos($output, '</body>');
        if ($position === false) {
            return;
        }

        $this->grav->output = substr_replace($output, $tag . "\n</body>", $position, 7);
    }

    /**
     * Build the tracker script tag from the plugin configuration, or return
     * null when the tracker must not appear on this response: plugin
     * disabled, missing instance URL or website token, admin context, a
     * Do Not Track request we are configured to respect, or a visitor who
     * can log in to Grav Admin while admin exclusion is on.
     */
    private function buildTrackerTag(): ?string
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

        // Respect Do Not Track: the tracker is not loaded at all.
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

        // Configuration travels in the script URL (documented URL-parameter
        // form), so the tag needs no inline JavaScript and stays friendly
        // to strict Content Security Policies.
        $params = [];
        if (!empty($config['minified'])) {
            $params['min'] = '1';
        }
        $params['endpoint'] = $instance . '/api/receive';
        $params['token'] = $token;

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
