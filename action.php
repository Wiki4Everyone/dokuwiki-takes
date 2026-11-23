<?php

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\Event;
use dokuwiki\Extension\EventHandler;

class action_plugin_takes extends ActionPlugin
{
    private $move = null;
    private $writing = false;
    private const CAP = 1000000000;
    private const MAX_N = 60000;
    public function register(EventHandler $controller)
    {
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'onPre');
        $controller->register_hook('TPL_CONTENT_DISPLAY', 'BEFORE', $this, 'onContent');
        $controller->register_hook('COMMON_WIKIPAGE_SAVE', 'BEFORE', $this, 'onSave', null, -50);
        $controller->register_hook('COMMON_WIKIPAGE_SAVE', 'AFTER', $this, 'onSaved');
        $controller->register_hook('AUTH_ACL_CHECK', 'AFTER', $this, 'onAcl');
        $controller->register_hook('AUTH_USER_CHANGE', 'AFTER', $this, 'onUserGone');
    }

    public function allowDelete($id, $user)
    {
        $part = $this->split($id);
        if (!$part || $user === '') return false;
        if (!$this->marked($part['base'])) return false;
        $slot = $this->slotByPage($part['page']);
        return $slot && $slot['login'] === $user && (int) $slot['n'] === $part['n'];
    }

    public function allowCreate($id, $user)
    {
        $part = $this->split($id);
        if (!$part || $user === '') return false;
        if (!$this->marked($part['base'])) return false;
        $slot = $this->slotByPage($part['page']);
        return $slot && $slot['login'] === $user && (int) $slot['n'] === $part['n'];
    }

    public function onPre(Event $event, $param)
    {
        global $INPUT;
        $do = $INPUT->post->str('takes_do');
        if ($do !== '') {
            $jump = '';
            if (checkSecurityToken()) {
                if ($do === 'add') $jump = $this->addOne();
                if ($do === 'up') $this->cast(1);
                if ($do === 'down') $this->cast(-1);
                if ($do === 'unhide') $this->doUnhide();
                if ($do === 'alert') $this->setAlert();
                if ($do === 'alert-off') $this->clearAlert();
            }
            if ($jump !== '') {
                send_redirect(wl($jump, ['do' => 'edit'], true, '&'));
            }
            $back = cleanID($INPUT->post->str('id'));
            if ($back === '') $back = $this->pageId();
            send_redirect(wl($back));
        }
        $act = $this->actName($event->data);
        $id = $this->pageId();
        $user = $this->user();
        if (($act === 'show' || $act === '') && $this->buriedFor($id, $user)) {
            http_status(404);
        }
        if (!in_array($act, ['edit', 'preview', 'save', 'recover', 'draft', 'revert'], true)) return;
        if ($this->baseLocked($id, $user)) {
            msg($this->tx($this->lang($id), 'base'), -1);
            $event->data = 'show';
            return;
        }
        if (!$this->lockedTake($id, $user)) return;
        msg($this->tx($this->lang($id), 'taken'), -1);
        $event->data = 'show';
    }

    public function onContent(Event $event, $param)
    {
        $id = $this->pageId();
        $part = $this->split($id);
        if ($part && $this->marked($part['base'])) {
            $event->data = $this->versionBanner($part) . $event->data;
            if ($this->buriedFor($id, $this->user())) {
                $event->data = '<h1>' . hsc($this->tx($this->lang($id), 'missing')) . '</h1>';
            }
            return;
        }
        if ($id === '' || !$this->marked($id)) return;
        $event->data .= $this->listHtml($id);
    }

    public function onSave(Event $event, $param)
    {
        $id = cleanID((string) ($event->data['id'] ?? ''));
        if ($this->writing) return;
        $new = (string) ($event->data['newContent'] ?? '');
        $old = (string) ($event->data['oldContent'] ?? '');
        $user = $this->user();
        $adding = strpos($new, '~~TAKES~~') !== false && strpos($old, '~~TAKES~~') === false;
        if ($adding && !$this->isStaff($user)) {
            msg($this->tx($this->lang($id), 'staff'), -1);
            $event->preventDefault();
            return;
        }
        if ($adding && $this->isStaff($user) && !$this->slotByPage($id . '.0')) {
            $this->move = ['base' => $id, 'body' => trim(str_replace('~~TAKES~~', '', $new))];
            $event->data['newContent'] = "~~TAKES~~\n\n" . $this->tx($this->lang($id), 'shell') . "\n";
        }
        if ($this->baseLocked($id, $user)) {
            msg($this->tx($this->lang($id), 'base'), -1);
            $event->preventDefault();
            return;
        }
        if (!$this->lockedTake($id, $user)) return;
        msg($this->tx($this->lang($id), 'taken'), -1);
        $event->preventDefault();
    }

    public function onSaved(Event $event, $param)
    {
        if (!$this->move || !$event->mayRunDefault()) {
            $this->move = null;
            return;
        }
        $base = $this->move['base'];
        $body = $this->move['body'];
        $this->move = null;
        $this->migrate($base, $body);
    }

    public function onAcl(Event $event, $param)
    {
        $id = cleanID((string) ($event->data['id'] ?? ''));
        $user = (string) ($event->data['user'] ?? '');
        $groups = (array) ($event->data['groups'] ?? []);
        if ($this->marked($id) && !$this->split($id)) {
            if (!$this->eventIsAdmin($user, $groups) && (int) $event->result > AUTH_READ) {
                $event->result = AUTH_READ;
            }
            return;
        }
        $part = $this->split($id);
        if ($part && (int) $part['n'] === 0 && $this->marked($part['base'])) {
            if ((int) $event->result > AUTH_READ) $event->result = AUTH_READ;
            return;
        }
        if (!$part || !$this->marked($part['base'])) return;
        $slot = $this->slotByPage($id);
        if ($slot && $user !== '' && $slot['login'] === $user) {
            if ((int) $event->result < AUTH_DELETE) $event->result = AUTH_DELETE;
            return;
        }
        if ((int) $event->result > AUTH_READ) $event->result = AUTH_READ;
    }

    public function onUserGone(Event $event, $param)
    {
        if (($event->data['type'] ?? '') !== 'delete') return;
        $names = $event->data['params'][0] ?? [];
        if (is_string($names)) $names = [$names];
        if (!is_array($names)) return;
        $db = $this->db();
        if (!$db) return;
        foreach ($names as $name) {
            if (!is_string($name) || $name === '') continue;
            $db->query('DELETE FROM take_vote WHERE login = ?', $name);
            $db->query('DELETE FROM take_slot WHERE login = ?', $name);
            $db->query('DELETE FROM take_alert WHERE login = ?', $name);
        }
    }

    private function addOne()
    {
        global $INPUT;
        $user = $this->user();
        $base = cleanID($INPUT->post->str('id'));
        $lang = $this->lang($base);
        if ($user === '') {
            msg($this->tx($lang, 'login'), -1);
            return '';
        }
        if (!$this->marked($base)) return '';
        $have = $this->owned($base, $user);
        if ($have !== '') {
            $this->ensurePage($have, $lang);
            return $have;
        }
        $page = $this->reserve($base, $user);
        if ($page === '') {
            msg($this->tx($lang, 'fail'), -1);
            return '';
        }
        $this->ensurePage($page, $lang);
        return $page;
    }

    private function ensurePage($page, $lang)
    {
        if ($page === '' || page_exists($page)) return;
        $this->writeTxt($page, $this->tx($lang, 'stub') . "\n");
    }

    private function baseLocked($id, $user)
    {
        return $this->marked($id) && !$this->split($id) && !$this->isStaff($user);
    }

    private function isAdmin($user)
    {
        global $INFO;
        $groups = (array) ($INFO['userinfo']['grps'] ?? []);
        return $this->eventIsAdmin($user, $groups);
    }

    private function eventIsAdmin($user, $groups)
    {
        if (in_array('admin', (array) $groups, true)) return true;
        return $user !== '' && function_exists('auth_isadmin') && auth_isadmin($user, (array) $groups);
    }

    private function cast($vote)
    {
        global $INPUT;
        $user = $this->user();
        $page = cleanID($INPUT->post->str('takes_page'));
        $base = cleanID($INPUT->post->str('id'));
        $lang = $this->lang($base !== '' ? $base : $page);
        if ($user === '') {
            msg($this->tx($lang, 'login'), -1);
            return;
        }
        $part = $this->split($page);
        if (!$part || !$this->marked($part['base']) || !$this->slotByPage($page)) return;
        $db = $this->db();
        if (!$db) return;
        $row = $db->res2row($db->query('SELECT vote FROM take_vote WHERE page = ? AND login = ?', $page, $user));
        if ($row && (int) $row['vote'] === $vote) {
            $db->query('DELETE FROM take_vote WHERE page = ? AND login = ?', $page, $user);
            $this->afterVote($page);
            return;
        }
        [$net, $up] = $this->totals($page);
        $old = $row ? (int) $row['vote'] : 0;
        $next = $net - $old + $vote;
        if ($next > self::CAP || $next < -self::CAP) {
            msg($this->tx($lang, 'cap'), -1);
            return;
        }
        $db->query(
            'INSERT INTO take_vote (page, login, vote, time) VALUES (?, ?, ?, ?)
             ON CONFLICT(page, login) DO UPDATE SET vote = excluded.vote, time = excluded.time',
            $page,
            $user,
            $vote,
            time()
        );
        $this->afterVote($page);
    }

    private function listHtml($base)
    {
        $lang = $this->lang($base);
        $user = $this->user();
        $rows = $this->ranked($base, $user);
        $mine = $user === '' ? '' : $this->owned($base, $user);
        $html = $this->css();
        $html .= '<div class="takes">';
        $html .= '<h2 class="takes-title">' . hsc($this->tx($lang, 'title')) . '</h2>';
        if ($user === '') {
            $html .= '<p class="takes-note">' . hsc($this->tx($lang, 'login')) . '</p>';
        } else {
            $html .= '<form class="takes-add" method="post" action="' . wl($base) . '">';
            $html .= formSecurityToken(false);
            $html .= '<input type="hidden" name="id" value="' . hsc($base) . '">';
            $html .= '<input type="hidden" name="takes_do" value="add">';
            $label = $mine === '' ? $this->tx($lang, 'add') : $this->tx($lang, 'yours');
            $html .= '<button type="submit">' . hsc($label) . '</button></form>';
        }
        if (!$rows) {
            $html .= '<p class="takes-empty">' . hsc($this->tx($lang, 'empty')) . '</p></div>';
            return $html;
        }
        $html .= '<ol class="takes-list">';
        foreach ($rows as $row) {
            $page = $row['page'];
            $title = p_get_first_heading($page);
            if (!$title) $title = noNS($page);
            $mineVote = (int) ($row['votes'][$user] ?? 0);
            $html .= '<li class="takes-item">';
            $html .= '<div class="takes-row">';
            $html .= '<span class="takes-score">' . hsc($this->tx($lang, 'score')) . ' ' . (int) $row['score'] . '</span>';
            $html .= '<a href="' . wl($page) . '">' . hsc($title) . '</a>';
            $html .= '</div>';
            if ($row['snippet'] !== '') {
                $html .= '<p class="takes-snip">' . hsc($row['snippet']) . '</p>';
            }
            if ((int) $row['n'] === 0) {
                $html .= '<p class="takes-note">' . hsc($this->tx($lang, 'copy')) . '</p>';
            }
            if (!empty($row['hidden'])) {
                $html .= '<p class="takes-note">' . hsc($this->tx($lang, 'hidden')) . '</p>';
            }
            if ($user !== '') {
                $html .= '<div class="takes-row">';
                $html .= $this->voteForm($base, $page, 'up', $this->tx($lang, 'up'), $mineVote === 1);
                $html .= $this->voteForm($base, $page, 'down', $this->tx($lang, 'down'), $mineVote === -1);
                $html .= '</div>';
                $html .= $this->alertForm($base, $page, $lang, $user);
            }
            $html .= '</li>';
        }
        $html .= '</ol></div>';
        return $html;
    }

    private function voteForm($base, $page, $do, $label, $on)
    {
        $html = '<form method="post" action="' . wl($base) . '">';
        $html .= formSecurityToken(false);
        $html .= '<input type="hidden" name="id" value="' . hsc($base) . '">';
        $html .= '<input type="hidden" name="takes_page" value="' . hsc($page) . '">';
        $html .= '<input type="hidden" name="takes_do" value="' . $do . '">';
        $html .= '<button type="submit"' . ($on ? ' class="takes-on"' : '') . '>' . hsc($label) . '</button>';
        $html .= '</form>';
        return $html;
    }

    private function ranked($base, $user = null)
    {
        $db = $this->db();
        if (!$db) return [];
        $slots = $db->res2arr($db->query('SELECT page, n, login, hidden FROM take_slot WHERE base = ?', $base));
        $voteRows = $db->res2arr($db->query(
            'SELECT v.page, v.login, v.vote FROM take_vote v JOIN take_slot s ON s.page = v.page WHERE s.base = ?',
            $base
        ));
        $byPage = [];
        foreach ($voteRows as $vote) {
            $byPage[$vote['page']][$vote['login']] = (int) $vote['vote'];
        }
        $rows = [];
        foreach ($slots as $slot) {
            $page = (string) $slot['page'];
            if (!page_exists($page)) continue;
            if ((int) ($slot['hidden'] ?? 0) === 1 && $slot['login'] !== $user) continue;
            $votes = $byPage[$page] ?? [];
            $ups = 0;
            foreach ($votes as $vote) {
                if ($vote > 0) $ups += $vote;
            }
            $rows[] = [
                'page' => $page,
                'n' => (int) $slot['n'],
                'login' => (string) $slot['login'],
                'hidden' => (int) ($slot['hidden'] ?? 0),
                'score' => array_sum($votes),
                'ups' => $ups,
                'votes' => $votes,
                'snippet' => $this->snippet($page),
            ];
        }
        usort($rows, function ($a, $b) {
            if ($a['score'] !== $b['score']) return $b['score'] <=> $a['score'];
            return $b['n'] <=> $a['n'];
        });
        return $rows;
    }

    private function reserve($base, $user)
    {
        $db = $this->db();
        if (!$db) return '';
        for ($try = 0; $try < 5; $try++) {
            $row = $db->res2row($db->query('SELECT COALESCE(MAX(n), 0) AS n FROM take_slot WHERE base = ?', $base));
            $n = (int) ($row['n'] ?? 0) + 1;
            if ($n > self::MAX_N) {
                msg($this->tx($this->lang($base), 'limit'), -1);
                return '';
            }
            $page = $base . '.' . $n;
            try {
                $ok = $db->query(
                    'INSERT INTO take_slot (base, n, page, login, created) VALUES (?, ?, ?, ?, ?)',
                    $base,
                    $n,
                    $page,
                    $user,
                    time()
                );
            } catch (Throwable $e) {
                $ok = false;
            }
            if ($ok) return $page;
            $have = $this->owned($base, $user);
            if ($have !== '') return $have;
        }
        return '';
    }

    private function owned($base, $user)
    {
        $db = $this->db();
        if (!$db || $user === '') return '';
        $row = $db->res2row($db->query('SELECT page FROM take_slot WHERE base = ? AND login = ?', $base, $user));
        return (string) ($row['page'] ?? '');
    }

    private function slotByPage($page)
    {
        $db = $this->db();
        if (!$db || $page === '') return null;
        $row = $db->res2row($db->query('SELECT base, n, page, login, hidden, down_notified FROM take_slot WHERE page = ?', $page));
        return $row ?: null;
    }

    private function lockedTake($id, $user)
    {
        $part = $this->split($id);
        if (!$part || !$this->marked($part['base'])) return false;
        $slot = $this->slotByPage($part['page']);
        return !$slot || $slot['login'] !== $user;
    }

    private function marked($id)
    {
        if ($id === '' || !page_exists($id)) return false;
        return strpos(rawWiki($id), '~~TAKES~~') !== false;
    }

    private function split($id)
    {
        $id = cleanID((string) $id);
        if (!preg_match('/^(.*)\.(\d+)$/', $id, $m)) return null;
        if ($m[2] !== '0' && $m[2][0] === '0') return null;
        if ((int) $m[2] > self::MAX_N) return null;
        return ['base' => $m[1], 'n' => (int) $m[2], 'page' => $id];
    }

    private function snippet($id)
    {
        $raw = rawWiki($id);
        $raw = str_replace('~~TAKES~~', ' ', $raw);
        $raw = preg_replace('/\[\[(?:[^|\]]+\|)?([^\]]+)\]\]/', '$1', $raw);
        $raw = preg_replace('/\{\{[^}]*\}\}|<[^>\n]+>|~~[^~\n]+~~|==+|[\*]+|^[#\-].*/m', ' ', $raw);
        $raw = trim(preg_replace('/\s+/u', ' ', $raw));
        if (mb_strlen($raw) > 180) $raw = mb_substr($raw, 0, 180) . '…';
        return $raw;
    }

    public function canUnhide($user, $page)
    {
        $slot = $this->slotByPage($page);
        return $slot && (int) ($slot['hidden'] ?? 0) === 1 && $user !== '' && $slot['login'] === $user;
    }

    private function migrate($base, $body)
    {
        $lang = $this->lang($base);
        $page0 = $base . '.0';
        if (!$this->slotByPage($page0)) {
            $db = $this->db();
            if (!$db) return;
            $db->query(
                'INSERT INTO take_slot (base, n, page, login, created) VALUES (?, 0, ?, ?, ?)',
                $base,
                $page0,
                '',
                time()
            );
            $text = $body === '' ? $this->tx($lang, 'empty0') : $body;
            $this->writeTxt($page0, $text);
        }
        $n = 0;
        $row = $this->db()->res2row($this->db()->query('SELECT COALESCE(MAX(n), 0) AS n FROM take_slot WHERE base = ? AND n > 0', $base));
        $n = (int) ($row['n'] ?? 0);
        foreach ($this->editorsOf($base) as $login) {
            if ($login === '' || $this->owned($base, $login) !== '') continue;
            $n++;
            if ($n > self::MAX_N) {
                msg($this->tx($lang, 'limit'), -1);
                break;
            }
            $page = $base . '.' . $n;
            try {
                $ok = $this->db()->query(
                    'INSERT INTO take_slot (base, n, page, login, created) VALUES (?, ?, ?, ?, ?)',
                    $base,
                    $n,
                    $page,
                    $login,
                    time()
                );
            } catch (Throwable $e) {
                $ok = false;
            }
            if ($ok) $this->writeTxt($page, $this->tx($lang, 'stub'));
        }
    }

    private function editorsOf($id)
    {
        $file = metaFN($id, '.changes');
        $names = [];
        if (is_file($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                $cols = explode("\t", $line);
                $name = $cols[4] ?? '';
                if ($name !== '') $names[$name] = true;
            }
        }
        $list = array_keys($names);
        sort($list, SORT_STRING);
        return $list;
    }

    private function writeTxt($id, $text)
    {
        if ($id === '' || page_exists($id)) return;
        $file = wikiFN($id);
        io_makeFileDir($file);
        if (substr($text, -1) !== "\n") $text .= "\n";
        io_saveFile($file, $text);
        if (function_exists('idx_addPage')) idx_addPage($id);
    }

    private function versionBanner($part)
    {
        $lang = $this->lang($part['page']);
        $html = '';
        if ((int) $part['n'] === 0) {
            $html .= '<p class="takes-note">' . hsc($this->tx($lang, 'copy')) . '</p>';
        }
        $slot = $this->slotByPage($part['page']);
        $user = $this->user();
        if ($slot && (int) ($slot['hidden'] ?? 0) === 1 && $user !== '' && $slot['login'] === $user) {
            $html .= '<form method="post" action="' . wl($part['page']) . '">';
            $html .= formSecurityToken(false);
            $html .= '<input type="hidden" name="id" value="' . hsc($part['base']) . '">';
            $html .= '<input type="hidden" name="takes_page" value="' . hsc($part['page']) . '">';
            $html .= '<input type="hidden" name="takes_do" value="unhide">';
            $html .= '<button type="submit">' . hsc($this->tx($lang, 'unhide')) . '</button></form>';
        }
        return $html;
    }

    private function doUnhide()
    {
        global $INPUT;
        $user = $this->user();
        $page = cleanID($INPUT->post->str('takes_page'));
        if (!$this->canUnhide($user, $page)) return;
        $db = $this->db();
        if (!$db) return;
        $db->query('UPDATE take_slot SET hidden = 0 WHERE page = ? AND login = ?', $page, $user);
    }

    private function setAlert()
    {
        global $INPUT;
        $user = $this->user();
        $page = cleanID($INPUT->post->str('takes_page'));
        $base = cleanID($INPUT->post->str('id'));
        $lang = $this->lang($base !== '' ? $base : $page);
        if ($user === '') {
            msg($this->tx($lang, 'login'), -1);
            return;
        }
        $part = $this->split($page);
        if (!$part || !$this->marked($part['base']) || !$this->slotByPage($page)) return;
        $n = (int) $INPUT->post->str('takes_n');
        if ($n < 15) $n = 15;
        if ($n > 5000) $n = 5000;
        $db = $this->db();
        if (!$db) return;
        $have = $db->res2row($db->query('SELECT login FROM take_alert WHERE login = ? AND page = ?', $user, $page));
        if (!$have && $this->alertFull($user)) {
            msg($this->tx($lang, 'full'), -1);
            return;
        }
        $db->query(
            'INSERT INTO take_alert (login, page, threshold, hit_up, hit_top) VALUES (?, ?, ?, 0, 0)
             ON CONFLICT(login, page) DO UPDATE SET threshold = excluded.threshold',
            $user,
            $page,
            $n
        );
    }

    private function clearAlert()
    {
        global $INPUT;
        $user = $this->user();
        $page = cleanID($INPUT->post->str('takes_page'));
        $db = $this->db();
        if (!$db || $user === '' || $page === '') return;
        $db->query('DELETE FROM take_alert WHERE login = ? AND page = ?', $user, $page);
    }

    private function alertForm($base, $page, $lang, $user)
    {
        $db = $this->db();
        $have = $db ? $db->res2row($db->query('SELECT threshold FROM take_alert WHERE login = ? AND page = ?', $user, $page)) : null;
        $html = '<form class="takes-alert" method="post" action="' . wl($base) . '">';
        $html .= formSecurityToken(false);
        $html .= '<input type="hidden" name="id" value="' . hsc($base) . '">';
        $html .= '<input type="hidden" name="takes_page" value="' . hsc($page) . '">';
        if ($have) {
            $html .= '<input type="hidden" name="takes_do" value="alert-off">';
            $html .= '<button type="submit">' . hsc($this->tx($lang, 'alertset') . ' ' . (int) $have['threshold'] . ' · ' . $this->tx($lang, 'alertoff')) . '</button>';
        } else {
            $html .= '<input type="hidden" name="takes_do" value="alert">';
            $html .= '<input type="number" name="takes_n" min="15" max="5000" value="15">';
            $html .= '<button type="submit">' . hsc($this->tx($lang, 'alert')) . '</button>';
        }
        $html .= '</form>';
        return $html;
    }

    private function alertFull($user)
    {
        $box = plugin_load('action', 'sitebox');
        $used = $this->alertCount($user);
        if ($box && method_exists($box, 'watchCount')) $used += $box->watchCount($user);
        $max = ($box && method_exists($box, 'watchMax')) ? $box->watchMax($user) : 5;
        return $used >= $max;
    }

    private function alertCount($user)
    {
        $db = $this->db();
        if (!$db || $user === '') return 0;
        $row = $db->res2row($db->query('SELECT COUNT(*) AS n FROM take_alert WHERE login = ?', $user));
        return (int) ($row['n'] ?? 0);
    }

    private function totals($page)
    {
        $db = $this->db();
        $net = 0;
        $up = 0;
        if (!$db) return [0, 0];
        foreach ($db->res2arr($db->query('SELECT vote FROM take_vote WHERE page = ?', $page)) as $row) {
            $vote = (int) $row['vote'];
            $net += $vote;
            if ($vote > 0) $up += $vote;
        }
        return [$net, $up];
    }

    private function afterVote($page)
    {
        $part = $this->split($page);
        if (!$part) return;
        $slot = $this->slotByPage($page);
        if (!$slot) return;
        [$net, $up] = $this->totals($page);
        $db = $this->db();
        if ($net <= -20 && (int) ($slot['down_notified'] ?? 0) === 0) {
            $db->query('UPDATE take_slot SET hidden = 1, down_notified = 1 WHERE page = ?', $page);
            if ($slot['login'] !== '') {
                $box = plugin_load('action', 'sitebox');
                if ($box && method_exists($box, 'deliverWatch')) {
                    $box->deliverWatch($slot['login'], $page, $this->tx($this->lang($page), 'buried'), 'bury');
                }
            }
        }
        $top = $this->isTop($part['base'], $page);
        foreach ($db->res2arr($db->query('SELECT login, threshold, hit_up, hit_top FROM take_alert WHERE page = ?', $page)) as $alert) {
            if ($up >= (int) $alert['threshold'] && (int) $alert['hit_up'] === 0) {
                $db->query('UPDATE take_alert SET hit_up = 1 WHERE login = ? AND page = ?', $alert['login'], $page);
                $this->tell($alert['login'], $page, 'peaked');
            }
            if ($top && (int) $alert['hit_top'] === 0) {
                $db->query('UPDATE take_alert SET hit_top = 1 WHERE login = ? AND page = ?', $alert['login'], $page);
                $this->tell($alert['login'], $page, 'peaked');
            }
        }
    }

    private function tell($user, $page, $key)
    {
        $box = plugin_load('action', 'sitebox');
        if (!$box || !method_exists($box, 'deliverWatch')) return;
        $box->deliverWatch($user, $page, $this->tx($this->lang($page), $key), 'peak');
    }

    private function isTop($base, $page)
    {
        $rows = $this->ranked($base, null);
        return $rows && $rows[0]['page'] === $page;
    }

    private function buriedFor($id, $user)
    {
        $part = $this->split($id);
        if (!$part || !$this->marked($part['base'])) return false;
        $slot = $this->slotByPage($id);
        if (!$slot || (int) ($slot['hidden'] ?? 0) !== 1) return false;
        if ($user !== '' && $slot['login'] === $user) return false;
        if ($this->isAdmin($user)) return false;
        return true;
    }

    private function isStaff($user)
    {
        global $INFO, $auth;
        if ($user === '') return false;
        $groups = [];
        if ($user === $this->user()) $groups = (array) ($INFO['userinfo']['grps'] ?? []);
        if (!$groups) {
            if (!$auth && function_exists('auth_setup')) auth_setup();
            global $auth;
            $info = $auth ? $auth->getUserData($user) : null;
            $groups = (array) ($info['grps'] ?? []);
        }
        if (in_array('admin', $groups, true) || in_array('mod', $groups, true)) return true;
        return function_exists('auth_isadmin') && auth_isadmin($user, $groups);
    }

    private function db()
    {
        static $sqlite = false;
        if ($sqlite !== false) return $sqlite ?: null;
        $sqlite = plugin_load('helper', 'sqlite');
        if (!$sqlite || !$sqlite->init('takes', DOKU_PLUGIN . 'takes/db')) {
            $sqlite = null;
            return null;
        }
        return $sqlite;
    }

    private function user()
    {
        global $INPUT;
        return $INPUT->server->str('REMOTE_USER');
    }

    private function pageId()
    {
        global $ID, $INPUT;
        $id = (string) $ID;
        if ($id === '') $id = $INPUT->str('id');
        return cleanID($id);
    }

    private function actName($data)
    {
        if (is_string($data)) return $data;
        if (is_array($data)) return (string) key($data);
        return '';
    }

    private function lang($id)
    {
        $map = [
            'zh_sim_high' => 'sim', 'zh_sim' => 'sim',
            'zh_tra_high' => 'tra', 'zh_tra' => 'tra',
            'fr_high' => 'fr', 'fr' => 'fr',
            'de_high' => 'de', 'de' => 'de',
            'es_high' => 'es', 'es' => 'es',
            'ja_high' => 'ja', 'ja' => 'ja',
        ];
        foreach ($map as $ns => $lang) {
            if (strpos($id, $ns . ':') === 0) return $lang;
        }
        return 'en';
    }

    private function tx($lang, $key)
    {
        $text = [
            'en' => [
                'title' => 'Versions', 'add' => 'Add your version', 'yours' => 'Your version',
                'login' => 'Log in to add a version or to vote.', 'score' => 'Score',
                'up' => 'Up', 'down' => 'Down', 'empty' => 'No versions yet.',
                'taken' => 'This version belongs to someone else.', 'fail' => 'Could not reserve a version.',
                'base' => 'This page is not edited. Add your version below.',
                'stub' => 'This is your version. Copy from .0, then change this page. .0 cannot be edited.',
                'shell' => 'Versions are listed below. The original is .0 and cannot be edited. Copy it into your own version.',
                'copy' => 'This original cannot be edited. Copy it into your own version and change that.',
                'staff' => 'Only a moderator or an administrator can add ~~TAKES~~.',
                'cap' => 'Counting stops at one billion in either direction.',
                'limit' => 'This topic already has 60000 versions.',
                'full' => 'Your watch list is full.',
                'alert' => 'Notify me', 'alertset' => 'Notification on', 'alertoff' => 'Stop notices',
                'hidden' => 'Hidden.', 'unhide' => 'Show again', 'missing' => 'This topic does not exist',
                'buried' => 'Up and down votes now total -20 or lower, so this version is hidden. You can show it again. This notice is sent only once.',
                'peaked' => 'This version reached the top, or the number of upvotes you set.',
                'empty0' => 'The original page was empty.',
            ],
            'sim' => [
                'title' => '各种说法', 'add' => '加一个说法', 'yours' => '你的说法',
                'login' => '登录后才能加说法，也才能点赞或点踩。', 'score' => '评分',
                'up' => '赞成', 'down' => '反对', 'empty' => '还没有别的说法。',
                'taken' => '这是别人的说法，不能改。', 'fail' => '没有分到说法编号。',
                'base' => '这个总页面不能改。要加说法，用下面的按钮。',
                'stub' => '这是你的说法。原文在 .0，不能改。把原文复制过来再改。',
                'shell' => '各种说法列在下面。原来的内容在 .0，不能改。可以复制到你自己的说法里再改。',
                'copy' => '这篇原文不能改。可以复制到你自己的说法里再改。',
                'staff' => '只有版主或管理员可以加上 ~~TAKES~~。',
                'cap' => '赞成和反对加在一起，到了十亿或者负十亿就不再计。',
                'limit' => '这个主题的说法已经有 60000 个，不再分配。',
                'full' => '关注名额已经用完。',
                'alert' => '到这个赞数或榜首时通知我', 'alertset' => '已设通知', 'alertoff' => '取消通知',
                'hidden' => '已隐藏。', 'unhide' => '解除隐藏', 'missing' => '这个主题不存在',
                'buried' => '赞成和反对加在一起已经到 -20 或更低，这篇说法已隐藏。可以解除隐藏。这个通知只发一次。',
                'peaked' => '这篇说法到了榜首，或者到了你设的赞成数。',
                'empty0' => '原来的页面是空的。',
            ],
            'tra' => [
                'title' => '各種說法', 'add' => '加一個說法', 'yours' => '你的說法',
                'login' => '登入後才能加說法，也才能按讚或倒讚。', 'score' => '評分',
                'up' => '贊成', 'down' => '反對', 'empty' => '還沒有別的說法。',
                'taken' => '這是別人的說法，不能改。', 'fail' => '沒有分到說法編號。',
                'base' => '這個總頁面不能改。要加說法，用下面的按鈕。',
                'stub' => '這是你的說法。原文在 .0，不能改。把原文複製過來再改。',
                'shell' => '各種說法列在下面。原來的內容在 .0，不能改。可以複製到你自己的說法裡再改。',
                'copy' => '這篇原文不能改。可以複製到你自己的說法裡再改。',
                'staff' => '只有版主或管理員可以加上 ~~TAKES~~。',
                'cap' => '贊成和反對加在一起，到了十億或負十億就不再計。',
                'limit' => '這個主題的說法已經有 60000 個，不再分配。',
                'full' => '訂閱名額已經用完。',
                'alert' => '到這個讚數或榜首時通知我', 'alertset' => '已設通知', 'alertoff' => '取消通知',
                'hidden' => '已隱藏。', 'unhide' => '解除隱藏', 'missing' => '這個主題不存在',
                'buried' => '贊成和反對加在一起已經到 -20 或更低，這篇說法已隱藏。可以解除隱藏。這個通知只發一次。',
                'peaked' => '這篇說法到了榜首，或者到了你設的讚數。',
            ],
            'fr' => [
                'title' => 'Versions', 'add' => 'Ajouter votre version', 'yours' => 'Votre version',
                'login' => 'Connectez-vous pour ajouter une version ou voter.', 'score' => 'Score',
                'up' => 'Pour', 'down' => 'Contre', 'empty' => 'Pas encore de version.',
                'taken' => 'Cette version appartient à quelqu\'un d\'autre.', 'fail' => 'Numéro non attribué.',
                'base' => 'Cette page ne se modifie pas. Ajoutez une version plus bas.',
                'stub' => 'Remplacez ce texte par votre version.', 'stubsum' => 'Version attribuée',
            ],
            'de' => [
                'title' => 'Fassungen', 'add' => 'Eigene Fassung hinzufügen', 'yours' => 'Ihre Fassung',
                'login' => 'Melden Sie sich an, um eine Fassung hinzuzufügen oder abzustimmen.', 'score' => 'Wertung',
                'up' => 'Dafür', 'down' => 'Dagegen', 'empty' => 'Noch keine Fassung.',
                'taken' => 'Diese Fassung gehört jemand anderem.', 'fail' => 'Keine Nummer vergeben.',
                'base' => 'Diese Seite wird nicht bearbeitet. Eine Fassung fügen Sie unten hinzu.',
                'stub' => 'Ersetzen Sie diesen Text durch Ihre Fassung.', 'stubsum' => 'Fassung zugeteilt',
            ],
            'es' => [
                'title' => 'Versiones', 'add' => 'Añadir tu versión', 'yours' => 'Tu versión',
                'login' => 'Entra para añadir una versión o votar.', 'score' => 'Puntuación',
                'up' => 'A favor', 'down' => 'En contra', 'empty' => 'Todavía no hay versiones.',
                'taken' => 'Esta versión es de otra persona.', 'fail' => 'No se pudo reservar un número.',
                'base' => 'Esta página no se edita. Añada su versión debajo.',
                'stub' => 'Sustituya este texto por su versión.', 'stubsum' => 'Versión asignada',
            ],
            'ja' => [
                'title' => '別の言い方', 'add' => '言い方を足す', 'yours' => '自分の言い方',
                'login' => 'ログインすると、言い方を追加でき、投票もできます。', 'score' => '評点',
                'up' => '賛成', 'down' => '反対', 'empty' => 'まだ別の言い方はありません。',
                'taken' => 'これは他の人の言い方なので、変更できません。', 'fail' => '番号を取れませんでした。',
                'base' => 'このページ自体は編集できません。下のボタンから言い方を追加します。',
                'stub' => 'この文を、自分の言い方に書き換えてください。', 'stubsum' => '言い方を割り当て',
            ],
        ];
        return $text[$lang][$key] ?? $text['en'][$key] ?? $key;
    }

    private function css()
    {
        return '<style>'
            . '.takes{margin:1.2rem 0;padding-top:.8rem;border-top:1px solid rgba(127,127,127,.35)}'
            . '.takes-title{margin:0 0 .6rem;font-size:1.25rem}'
            . '.takes-list{list-style:none;margin:0;padding:0}'
            . '.takes-item{padding:.75rem 0;border-bottom:1px solid rgba(127,127,127,.25)}'
            . '.takes-row{display:flex;flex-wrap:wrap;gap:.5rem;align-items:center}'
            . '.takes form{display:inline-flex;margin:0}'
            . '.takes button{min-height:2.5rem;padding:.35rem .8rem}'
            . '.takes-on{font-weight:700}'
            . '.takes-snip{margin:.35rem 0 0}'
            . '.takes-score{min-width:4.5rem}'
            . '</style>';
    }
}
