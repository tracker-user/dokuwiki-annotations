<?php

/**
 * Annotations plugin — event registration and AJAX endpoint.
 *
 * Responsibilities:
 *
 *   1. Register a per-user "annotations_enabled" toggle via the usersettings
 *      plugin's PLUGIN_USERSETTINGS_REGISTER event (BEFORE, so it fires when
 *      the usersettings helper calls getRegisteredToggles()).
 *
 *   2. Push the current user's preference and the page's annotation stats
 *      into JSINFO on every normal page view, so script.js can gate itself
 *      and seed the counter without an extra round-trip.
 *
 *   3. Serve the AJAX endpoint at:
 *        /lib/exe/ajax.php?call=annotations
 *      POST body (application/json) carries { action, id, ... }.
 *      All state-changing actions require a valid DokuWiki security token.
 *      Every response is JSON: { success:true, ... } or { success:false, error:"..." }.
 *
 * Supported actions (all POST):
 *   create          — body, anchor (object)
 *   reply           — annId, body
 *   edit_annotation — annId, body
 *   edit_reply      — annId, replyId, body
 *   delete_annotation — annId
 *   delete_reply    — annId, replyId
 *   resolve         — annId, status ("open"|"resolved")
 *   clear_resolved  — (no extra fields)
 *   clear_orphaned  — (no extra fields)
 *
 * Permission enforcement is done here; the helper's permission methods are
 * called with facts gathered from the DokuWiki global state.
 */

// must be run within DokuWiki
if (!defined('DOKU_INC')) die();

class action_plugin_annotations extends DokuWiki_Action_Plugin
{
    // ------------------------------------------------------------------
    //  Event registration
    // ------------------------------------------------------------------

    /**
     * @param Doku_Event_Handler $controller
     */
    public function register(Doku_Event_Handler $controller)
    {
        // Register our toggle with the usersettings plugin.
        $controller->register_hook(
            'PLUGIN_USERSETTINGS_REGISTER',
            'BEFORE',
            $this,
            'handleSettingsRegister'
        );

        // Inject annotation stats + user preference into JSINFO.
        $controller->register_hook(
            'TPL_METAHEADER_OUTPUT',
            'BEFORE',
            $this,
            'handleMetaHeader'
        );

        // Handle the AJAX call.
        $controller->register_hook(
            'AJAX_CALL_UNKNOWN',
            'BEFORE',
            $this,
            'handleAjax'
        );
    }

    // ------------------------------------------------------------------
    //  1. usersettings toggle registration
    // ------------------------------------------------------------------

    /**
     * Append the annotations_enabled toggle definition to the event data.
     *
     * The event data is an array that the usersettings helper fires with
     * createAndTrigger(); every handler appends its definition(s).
     *
     * @param Doku_Event $event PLUGIN_USERSETTINGS_REGISTER
     * @param mixed       $param
     */
    public function handleSettingsRegister(Doku_Event $event, $param)
    {
        $event->data[] = [
            'key'     => 'annotations_enabled',
            'label'   => $this->getLang('toggle_label'),
            'desc'    => $this->getLang('toggle_desc'),
            'type'    => 'checkbox',
            'default' => true,
            'plugin'  => 'annotations',
        ];
    }

    // ------------------------------------------------------------------
    //  2. Inject into JSINFO
    // ------------------------------------------------------------------

    /**
     * Add annotation stats and the user preference to JSINFO so script.js
     * does not need an extra round-trip on page load.
     *
     * IMPORTANT: tpl_metaheaders() calls jsinfo() and then immediately
     * JSON-encodes $JSINFO into an inline <script> string BEFORE firing
     * TPL_METAHEADER_OUTPUT. Writing to $JSINFO here is therefore too late.
     * Instead we locate that inline script block in $event->data and append
     * a JSINFO.annotations = {...}; statement so it runs in the same scope.
     *
     * @param Doku_Event $event TPL_METAHEADER_OUTPUT
     * @param mixed       $param
     */
    public function handleMetaHeader(Doku_Event $event, $param)
    {
        global $ID, $ACT;

        // Only inject on normal page-view actions.
        if (!in_array(act_clean($ACT), ['show', 'export_xhtml'], true)) {
            return;
        }

        /** @var helper_plugin_annotations $helper */
        $helper = $this->loadHelper('annotations', false);
        if (!$helper) {
            return;
        }

        global $INFO;

        $enabled = $this->isEnabledForUser();
        $stats   = $helper->getStats($ID);

        // DokuWiki's jsinfo() does not expose user identity, so we inject it
        // here. JS uses these to gate the selection tooltip and permission UI.
        $user    = (string) ($_SERVER['REMOTE_USER'] ?? '');
        $isAdmin = !empty($INFO['isadmin']);

        $payload = json_encode([
            'enabled' => $enabled,
            'pageId'  => $ID,
            'stats'   => $stats,
            'user'    => $user,
            'isAdmin' => $isAdmin,
            'token'   => getSecurityToken(),  // CSRF token for AJAX POSTs
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // The inline script block containing "var JSINFO = ...;" is in
        // $event->data['script']. Find it and append our assignment so it
        // runs in the same scope after JSINFO is already declared.
        if (!empty($event->data['script'])) {
            foreach ($event->data['script'] as &$scriptTag) {
                if (
                    isset($scriptTag['_data']) &&
                    strpos($scriptTag['_data'], 'var JSINFO') !== false
                ) {
                    $scriptTag['_data'] .= 'JSINFO.annotations=' . $payload . ';';
                    break;
                }
            }
            unset($scriptTag);
        }
    }

    // ------------------------------------------------------------------
    //  3. AJAX endpoint
    // ------------------------------------------------------------------

    /**
     * Handle AJAX calls for the annotations plugin.
     * Ignores calls not addressed to us.
     *
     * @param Doku_Event $event AJAX_CALL_UNKNOWN
     * @param mixed       $param
     */
    public function handleAjax(Doku_Event $event, $param)
    {
        if ($event->data !== 'annotations') {
            return;
        }
        $event->stopPropagation();
        $event->preventDefault();

        header('Content-Type: application/json; charset=utf-8');

        // Parse JSON body; fall back to POST/GET fields for simple callers.
        // The 'load' action is a GET request, so we accept query parameters too.
        $payload = $this->readPayload();
        if ($payload === null) {
            $this->sendError('Invalid request body.');
            return;
        }

        $action = isset($payload['action']) ? (string) $payload['action'] : '';
        // For the read-only 'load' action, accept GET requests without a token.
        // All state-changing actions require a valid DokuWiki security token.
        if ($action !== 'load' && !checkSecurityToken()) {
            $this->sendError('Invalid security token.');
            return;
        }
        $id = isset($payload['id']) ? cleanID((string) $payload['id']) : '';

        if ($action === '' || $id === '') {
            $this->sendError('Missing action or page id.');
            return;
        }

        /** @var helper_plugin_annotations $helper */
        $helper = $this->loadHelper('annotations', false);
        if (!$helper) {
            $this->sendError('Annotations helper unavailable.');
            return;
        }

        // Gather facts once; pass them to the helper's permission methods.
        global $USERINFO;
        $user    = (string) ($_SERVER['REMOTE_USER'] ?? '');
        $isAdmin = (bool) ($USERINFO['grps'] ?? false)
            ? in_array('admin', (array) ($USERINFO['grps'] ?? []), true)
            : false;
        // also honour DokuWiki's own admin flag
        if (!$isAdmin) {
            global $INFO;
            $isAdmin = !empty($INFO['isadmin']);
        }
        $aclLevel = auth_quickaclcheck($id);

        // Route to the correct handler method.
        switch ($action) {
            case 'load':
                $this->actionLoad($helper, $id, $aclLevel);
                break;
            case 'create':
                $this->actionCreate($helper, $id, $payload, $user, $aclLevel);
                break;
            case 'reply':
                $this->actionReply($helper, $id, $payload, $user, $aclLevel);
                break;
            case 'edit_annotation':
                $this->actionEditAnnotation($helper, $id, $payload, $user, $isAdmin);
                break;
            case 'edit_reply':
                $this->actionEditReply($helper, $id, $payload, $user, $isAdmin);
                break;
            case 'delete_annotation':
                $this->actionDeleteAnnotation($helper, $id, $payload, $user, $isAdmin);
                break;
            case 'delete_reply':
                $this->actionDeleteReply($helper, $id, $payload, $user, $isAdmin);
                break;
            case 'resolve':
                $this->actionResolve($helper, $id, $payload, $user, $aclLevel);
                break;
            case 'clear_resolved':
                $this->actionClearResolved($helper, $id, $isAdmin);
                break;
            case 'clear_orphaned':
                $this->actionClearOrphaned($helper, $id, $isAdmin);
                break;
            default:
                $this->sendError('Unknown action: ' . hsc($action));
        }
    }

    // ------------------------------------------------------------------
    //  Action handlers (one per supported action)
    // ------------------------------------------------------------------

    /**
     * Create a new annotation.
     *
     * Payload: { action, id, anchor:{exact,prefix,suffix,start}, body }
     *
     * @param helper_plugin_annotations $helper
     * @param string                    $id
     * @param array                     $payload
     * @param string                    $user
     * @param int                       $aclLevel
     */
    protected function actionCreate($helper, $id, array $payload, $user, $aclLevel)
    {
        if (!$helper->canAnnotate($user, $aclLevel)) {
            $this->sendError('Permission denied.');
            return;
        }
        $anchor = isset($payload['anchor']) && is_array($payload['anchor'])
            ? $payload['anchor']
            : [];
        $body = isset($payload['body']) ? (string) $payload['body'] : '';

        $result = $helper->createAnnotation($id, $anchor, $user, $body);
        if ($result === false) {
            $this->sendError('Invalid annotation data.');
            return;
        }
        $this->sendSuccess(['annotation' => $result]);
    }

    /**
     * Add a reply to an existing annotation.
     *
     * Payload: { action, id, annId, body }
     *
     * @param helper_plugin_annotations $helper
     * @param string                    $id
     * @param array                     $payload
     * @param string                    $user
     * @param int                       $aclLevel
     */
    protected function actionReply($helper, $id, array $payload, $user, $aclLevel)
    {
        if (!$helper->canAnnotate($user, $aclLevel)) {
            $this->sendError('Permission denied.');
            return;
        }
        $annId = isset($payload['annId']) ? (string) $payload['annId'] : '';
        $body  = isset($payload['body'])  ? (string) $payload['body']  : '';

        if ($annId === '') {
            $this->sendError('Missing annId.');
            return;
        }
        $result = $helper->addReply($id, $annId, $user, $body);
        if ($result === false) {
            $this->sendError('Invalid reply data or annotation not found.');
            return;
        }
        $this->sendSuccess(['reply' => $result]);
    }

    /**
     * Edit an annotation's body text.
     *
     * Payload: { action, id, annId, body }
     *
     * @param helper_plugin_annotations $helper
     * @param string                    $id
     * @param array                     $payload
     * @param string                    $user
     * @param bool                      $isAdmin
     */
    protected function actionEditAnnotation($helper, $id, array $payload, $user, $isAdmin)
    {
        $annId = isset($payload['annId']) ? (string) $payload['annId'] : '';
        $body  = isset($payload['body'])  ? (string) $payload['body']  : '';

        if ($annId === '') {
            $this->sendError('Missing annId.');
            return;
        }
        $annotation = $helper->getAnnotation($id, $annId);
        if ($annotation === null) {
            $this->sendError('Annotation not found.');
            return;
        }
        if (!$helper->canEditAnnotation($annotation, $user, $isAdmin)) {
            $this->sendError('Permission denied.');
            return;
        }
        $ok = $helper->updateAnnotationBody($id, $annId, $body);
        if (!$ok) {
            $this->sendError('Invalid body or annotation not found.');
            return;
        }
        $this->sendSuccess(['annotation' => $helper->getAnnotation($id, $annId)]);
    }

    /**
     * Edit a reply's body text.
     *
     * Payload: { action, id, annId, replyId, body }
     *
     * @param helper_plugin_annotations $helper
     * @param string                    $id
     * @param array                     $payload
     * @param string                    $user
     * @param bool                      $isAdmin
     */
    protected function actionEditReply($helper, $id, array $payload, $user, $isAdmin)
    {
        $annId   = isset($payload['annId'])   ? (string) $payload['annId']   : '';
        $replyId = isset($payload['replyId']) ? (string) $payload['replyId'] : '';
        $body    = isset($payload['body'])    ? (string) $payload['body']    : '';

        if ($annId === '' || $replyId === '') {
            $this->sendError('Missing annId or replyId.');
            return;
        }
        $annotation = $helper->getAnnotation($id, $annId);
        if ($annotation === null) {
            $this->sendError('Annotation not found.');
            return;
        }
        // Find the reply to permission-check its author.
        $reply = null;
        foreach (($annotation['replies'] ?? []) as $r) {
            if (($r['id'] ?? '') === $replyId) {
                $reply = $r;
                break;
            }
        }
        if ($reply === null) {
            $this->sendError('Reply not found.');
            return;
        }
        if (!$helper->canEditReply($reply, $user, $isAdmin)) {
            $this->sendError('Permission denied.');
            return;
        }
        $ok = $helper->updateReply($id, $annId, $replyId, $body);
        if (!$ok) {
            $this->sendError('Invalid body or reply not found.');
            return;
        }
        $this->sendSuccess(['annotation' => $helper->getAnnotation($id, $annId)]);
    }

    /**
     * Delete an annotation and all its replies.
     *
     * Payload: { action, id, annId }
     *
     * @param helper_plugin_annotations $helper
     * @param string                    $id
     * @param array                     $payload
     * @param string                    $user
     * @param bool                      $isAdmin
     */
    protected function actionDeleteAnnotation($helper, $id, array $payload, $user, $isAdmin)
    {
        $annId = isset($payload['annId']) ? (string) $payload['annId'] : '';

        if ($annId === '') {
            $this->sendError('Missing annId.');
            return;
        }
        $annotation = $helper->getAnnotation($id, $annId);
        if ($annotation === null) {
            $this->sendError('Annotation not found.');
            return;
        }
        if (!$helper->canEditAnnotation($annotation, $user, $isAdmin)) {
            $this->sendError('Permission denied.');
            return;
        }
        $ok = $helper->deleteAnnotation($id, $annId);
        if (!$ok) {
            $this->sendError('Delete failed.');
            return;
        }
        $this->sendSuccess(['stats' => $helper->getStats($id)]);
    }

    /**
     * Delete a reply.
     *
     * Payload: { action, id, annId, replyId }
     *
     * @param helper_plugin_annotations $helper
     * @param string                    $id
     * @param array                     $payload
     * @param string                    $user
     * @param bool                      $isAdmin
     */
    protected function actionDeleteReply($helper, $id, array $payload, $user, $isAdmin)
    {
        $annId   = isset($payload['annId'])   ? (string) $payload['annId']   : '';
        $replyId = isset($payload['replyId']) ? (string) $payload['replyId'] : '';

        if ($annId === '' || $replyId === '') {
            $this->sendError('Missing annId or replyId.');
            return;
        }
        $annotation = $helper->getAnnotation($id, $annId);
        if ($annotation === null) {
            $this->sendError('Annotation not found.');
            return;
        }
        $reply = null;
        foreach (($annotation['replies'] ?? []) as $r) {
            if (($r['id'] ?? '') === $replyId) {
                $reply = $r;
                break;
            }
        }
        if ($reply === null) {
            $this->sendError('Reply not found.');
            return;
        }
        if (!$helper->canEditReply($reply, $user, $isAdmin)) {
            $this->sendError('Permission denied.');
            return;
        }
        $ok = $helper->deleteReply($id, $annId, $replyId);
        if (!$ok) {
            $this->sendError('Delete failed.');
            return;
        }
        $this->sendSuccess(['annotation' => $helper->getAnnotation($id, $annId)]);
    }

    /**
     * Resolve or reopen an annotation.
     *
     * Payload: { action, id, annId, status:"open"|"resolved" }
     *
     * @param helper_plugin_annotations $helper
     * @param string                    $id
     * @param array                     $payload
     * @param string                    $user
     * @param int                       $aclLevel
     */
    protected function actionResolve($helper, $id, array $payload, $user, $aclLevel)
    {
        if (!$helper->canAnnotate($user, $aclLevel)) {
            $this->sendError('Permission denied.');
            return;
        }
        $annId  = isset($payload['annId'])  ? (string) $payload['annId']  : '';
        $status = isset($payload['status']) ? (string) $payload['status'] : '';

        if ($annId === '') {
            $this->sendError('Missing annId.');
            return;
        }
        $ok = $helper->setStatus($id, $annId, $status, $user);
        if (!$ok) {
            $this->sendError('Invalid status or annotation not found.');
            return;
        }
        $this->sendSuccess(['annotation' => $helper->getAnnotation($id, $annId)]);
    }

    /**
     * Remove all resolved annotations on the page. Admin only.
     *
     * Payload: { action, id }
     *
     * @param helper_plugin_annotations $helper
     * @param string                    $id
     * @param bool                      $isAdmin
     */
    protected function actionClearResolved($helper, $id, $isAdmin)
    {
        if (!$helper->canClear($isAdmin)) {
            $this->sendError('Permission denied.');
            return;
        }
        $count = $helper->clearResolved($id);
        if ($count === false) {
            $this->sendError('Clear failed.');
            return;
        }
        $this->sendSuccess(['removed' => $count, 'stats' => $helper->getStats($id)]);
    }

    /**
     * Remove all orphaned annotations on the page. Admin only.
     *
     * Payload: { action, id }
     *
     * @param helper_plugin_annotations $helper
     * @param string                    $id
     * @param bool                      $isAdmin
     */
    protected function actionClearOrphaned($helper, $id, $isAdmin)
    {
        if (!$helper->canClear($isAdmin)) {
            $this->sendError('Permission denied.');
            return;
        }
        $count = $helper->clearOrphaned($id);
        if ($count === false) {
            $this->sendError('Clear failed.');
            return;
        }
        $this->sendSuccess(['removed' => $count, 'stats' => $helper->getStats($id)]);
    }

    // ------------------------------------------------------------------
    //  Utilities
    // ------------------------------------------------------------------

    /**
     * Whether the current user has the annotations_enabled preference on.
     *
     * If the usersettings plugin is absent the feature defaults to enabled.
     * Public so templates and tests can call it directly.
     *
     * @return bool
     */
    public function isEnabledForUser()
    {
        /** @var helper_plugin_usersettings|null $us */
        $us = plugin_load('helper', 'usersettings');
        if (!$us) {
            return true; // usersettings not installed — default on
        }
        $value = $us->getPreference('annotations_enabled');
        // getPreference returns null when the toggle is not registered yet
        // (e.g. very first page load before the event has fired).
        return ($value === null) ? true : (bool) $value;
    }

    /**
     * Parse the request body as JSON; also accepts form-encoded POSTs for
     * simpler test scripts.
     *
     * @return array|null
     */
    protected function readPayload()
    {
        $ct = $_SERVER['CONTENT_TYPE'] ?? '';
        if (strpos($ct, 'application/json') !== false) {
            $raw  = file_get_contents('php://input');
            $data = json_decode($raw, true);
            return is_array($data) ? $data : null;
        }
        // For GET requests (load action), read from query string.
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            return $_GET ? (array) $_GET : [];
        }
        // Fall back to form-encoded POST (useful for simple curl tests).
        return $_POST ? (array) $_POST : [];
    }

    /**
     * Return all annotations for a page (read-only, no token required).
     *
     * The ACL check is still enforced: only users with at least AUTH_READ
     * on the page can read its annotations.
     *
     * @param helper_plugin_annotations $helper
     * @param string                    $id
     * @param int                       $aclLevel
     */
    protected function actionLoad($helper, $id, $aclLevel)
    {
        if ($aclLevel < AUTH_READ) {
            $this->sendError('Permission denied.');
            return;
        }
        $annotations = $helper->getAnnotations($id);
        $this->sendSuccess(['annotations' => $annotations]);
    }

        /**
     * Emit a JSON success response and exit.
     *
     * @param array $extra additional fields merged into the response
     */
    protected function sendSuccess(array $extra = [])
    {
        echo json_encode(array_merge(['success' => true], $extra), JSON_PRETTY_PRINT);
    }

    /**
     * Emit a JSON error response and exit.
     *
     * @param string $message human-readable error
     */
    protected function sendError($message)
    {
        echo json_encode(['success' => false, 'error' => $message], JSON_PRETTY_PRINT);
    }
}
