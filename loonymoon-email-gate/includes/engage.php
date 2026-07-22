<?php
/**
 * Engagement toolkit — tour listings, surveys/polls, contests.
 * The remaining OpenStage feature tier that needs no external APIs.
 */

if (!defined('ABSPATH')) {
    exit;
}

/* ===========================================================================
 * TOUR LISTINGS
 * ======================================================================== */

add_shortcode('lmeg_tour', 'lmeg_shortcode_tour');
function lmeg_shortcode_tour($atts = []) {
    $atts = shortcode_atts(['past' => 'no', 'limit' => 50], $atts, 'lmeg_tour');
    global $wpdb;
    $tbl   = $wpdb->prefix . 'lmeg_tour_dates';
    $today = current_time('Y-m-d');
    $where = strtolower($atts['past']) === 'yes' ? '1=1' : $wpdb->prepare('show_date >= %s', $today);
    $rows  = $wpdb->get_results("SELECT * FROM $tbl WHERE $where ORDER BY show_date ASC LIMIT " . max(1, (int) $atts['limit']));

    $member = function_exists('lmeg_current_member') ? lmeg_current_member() : null;

    ob_start(); ?>
    <div class="lmeg-tour">
        <?php if (empty($rows)) : ?>
            <p class="lmeg-tour__empty">No upcoming dates — join the list and you'll hear first.</p>
        <?php else : foreach ($rows as $d) :
            $date = date_i18n('M j, Y', strtotime($d->show_date));
            $loc  = $d->city . ($d->region ? ', ' . $d->region : '');
        ?>
            <div class="lmeg-tour__row<?php echo $d->status === 'soldout' ? ' is-soldout' : ''; ?>">
                <div class="lmeg-tour__date"><?php echo esc_html($date); ?></div>
                <div class="lmeg-tour__where">
                    <div class="lmeg-tour__venue"><?php echo esc_html($d->venue); ?></div>
                    <div class="lmeg-tour__city"><?php echo esc_html($loc); ?></div>
                </div>
                <div class="lmeg-tour__cta">
                    <?php if ($d->status === 'soldout') : ?>
                        <span class="lmeg-tour__soldout">Sold out</span>
                    <?php else : ?>
                        <?php if ($d->presale_url) : ?>
                            <?php if (!$d->presale_members_only || $member) : ?>
                                <a class="lmeg-button lmeg-tour__btn" href="<?php echo esc_url($d->presale_url); ?>" target="_blank" rel="noopener">Presale</a>
                            <?php else : ?>
                                <span class="lmeg-tour__locked" title="Join the list to unlock presale">🔒 Presale for members</span>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($d->ticket_url) : ?>
                            <a class="lmeg-button lmeg-button--outline lmeg-tour__btn" href="<?php echo esc_url($d->ticket_url); ?>" target="_blank" rel="noopener">Tickets</a>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; endif; ?>
    </div>
    <?php return ob_get_clean();
}

/* ===========================================================================
 * SURVEYS / POLLS
 * ======================================================================== */

add_shortcode('lmeg_survey', 'lmeg_shortcode_survey');
function lmeg_shortcode_survey($atts = []) {
    $atts = shortcode_atts(['id' => 0], $atts, 'lmeg_survey');
    global $wpdb;
    $sid    = (int) $atts['id'];
    $survey = $sid ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}lmeg_surveys WHERE id = %d", $sid)) : null;
    if (!$survey) return '';

    $options = json_decode($survey->options_json, true) ?: [];
    $member  = function_exists('lmeg_current_member') ? lmeg_current_member() : null;
    $votes_t = $wpdb->prefix . 'lmeg_survey_votes';

    $my_vote = null;
    if ($member) {
        $my_vote = $wpdb->get_var($wpdb->prepare(
            "SELECT option_idx FROM $votes_t WHERE survey_id = %d AND subscriber_id = %d",
            $sid, $member->id
        ));
    }

    // Handle a vote submit (nonce-protected, members only, once each).
    if ($member && $survey->is_open && $my_vote === null
        && isset($_POST['lmeg_survey_id']) && (int) $_POST['lmeg_survey_id'] === $sid
        && isset($_POST['_lmeg_survey_nonce']) && wp_verify_nonce($_POST['_lmeg_survey_nonce'], 'lmeg_survey_' . $sid)
        && isset($_POST['lmeg_option'])) {
        $idx = (int) $_POST['lmeg_option'];
        if (isset($options[$idx])) {
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO $votes_t (survey_id, subscriber_id, option_idx, created_at) VALUES (%d, %d, %d, %s)",
                $sid, $member->id, $idx, current_time('mysql')
            ));
            $my_vote = $idx;
        }
    }

    // Tallies.
    $tallies = array_fill(0, count($options), 0);
    foreach ((array) $wpdb->get_results($wpdb->prepare(
        "SELECT option_idx, COUNT(*) AS n FROM $votes_t WHERE survey_id = %d GROUP BY option_idx", $sid
    )) as $r) {
        if (isset($tallies[(int) $r->option_idx])) $tallies[(int) $r->option_idx] = (int) $r->n;
    }
    $total = array_sum($tallies);

    $show_results = ($my_vote !== null) || !$survey->is_open;

    ob_start(); ?>
    <div class="lmeg-survey">
        <div class="lmeg-survey__q"><?php echo esc_html($survey->question); ?></div>

        <?php if (!$member) : ?>
            <p class="lmeg-survey__note">Join the list to vote — <a href="?lmeg_member=signin">sign in</a> if you already have.</p>
            <?php foreach ($options as $opt) : ?>
                <div class="lmeg-survey__opt is-disabled"><?php echo esc_html($opt); ?></div>
            <?php endforeach; ?>

        <?php elseif ($show_results) : ?>
            <?php foreach ($options as $i => $opt) :
                $pct = $total ? round(100 * $tallies[$i] / $total) : 0; ?>
                <div class="lmeg-survey__result<?php echo ((int) $my_vote === $i) ? ' is-mine' : ''; ?>">
                    <div class="lmeg-survey__bar" style="width:<?php echo $pct; ?>%;"></div>
                    <span class="lmeg-survey__label"><?php echo esc_html($opt); ?><?php echo ((int) $my_vote === $i) ? ' ✓' : ''; ?></span>
                    <span class="lmeg-survey__pct"><?php echo $pct; ?>%</span>
                </div>
            <?php endforeach; ?>
            <p class="lmeg-survey__note"><?php echo (int) $total; ?> vote<?php echo $total === 1 ? '' : 's'; ?><?php echo $survey->is_open ? '' : ' · poll closed'; ?></p>

        <?php else : ?>
            <form method="post">
                <input type="hidden" name="lmeg_survey_id" value="<?php echo $sid; ?>" />
                <?php wp_nonce_field('lmeg_survey_' . $sid, '_lmeg_survey_nonce'); ?>
                <?php foreach ($options as $i => $opt) : ?>
                    <button type="submit" name="lmeg_option" value="<?php echo $i; ?>" class="lmeg-survey__opt"><?php echo esc_html($opt); ?></button>
                <?php endforeach; ?>
            </form>
        <?php endif; ?>
    </div>
    <?php return ob_get_clean();
}

/* ===========================================================================
 * CONTESTS
 * ======================================================================== */

/**
 * Total entries for a fan in a contest: 1 for entering + 3 per fan they
 * referred while the contest is open.
 */
function lmeg_contest_bonus_entries($contest, $subscriber_id) {
    global $wpdb;
    // Referral bonus can be switched off per contest — then everyone has 1 entry.
    if (isset($contest->referral_bonus) && !$contest->referral_bonus) return 1;
    $subs = $wpdb->prefix . LMEG_TABLE;
    $end  = $contest->ends_at ?: current_time('mysql');
    $n = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $subs WHERE referred_by = %d AND created_at BETWEEN %s AND %s",
        (int) $subscriber_id, $contest->created_at, $end
    ));
    return 1 + 3 * $n;
}

/** Enter a subscriber into a contest if it's still open. Returns true if entered. */
function lmeg_contest_enter_subscriber($sub_id, $contest_id) {
    global $wpdb;
    $contest = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}lmeg_contests WHERE id = %d", (int) $contest_id));
    if (!$contest || !$contest->is_open || ($contest->ends_at && $contest->ends_at <= current_time('mysql'))) return false;
    $wpdb->query($wpdb->prepare(
        "INSERT IGNORE INTO {$wpdb->prefix}lmeg_contest_entries (contest_id, subscriber_id, entries, entered_at) VALUES (%d, %d, 1, %s)",
        (int) $contest_id, (int) $sub_id, current_time('mysql')
    ));
    return true;
}

/* ---------------------------------------------------------------------------
 * One-tap contest entry links — personalized + HMAC-signed. Put {contest_link}
 * in an email/SMS broadcast; each recipient gets a link that signs them in AND
 * enters them into the newest open contest with a single tap.
 * ------------------------------------------------------------------------- */

/** Newest still-running contest (open + not past its end). */
function lmeg_current_open_contest() {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}lmeg_contests
          WHERE is_open = 1 AND (ends_at IS NULL OR ends_at > %s)
          ORDER BY id DESC LIMIT 1",
        current_time('mysql')
    ));
}

function lmeg_contest_enter_token($contest_id, $sub_id, $exp) {
    return substr(hash_hmac('sha256', 'enter|' . (int) $contest_id . '|' . (int) $sub_id . '|' . (int) $exp, lmeg_get_secret()), 0, 32);
}

function lmeg_contest_enter_url($contest_id, $sub_id) {
    $exp = time() + 60 * DAY_IN_SECONDS;
    return add_query_arg([
        'lmeg_enter' => (int) $contest_id,
        'u'          => (int) $sub_id,
        'e'          => $exp,
        't'          => lmeg_contest_enter_token($contest_id, $sub_id, $exp),
    ], home_url('/'));
}

/** {contest_link} target for a fan: newest open contest, or home if none. */
function lmeg_contest_link_for($sub_id) {
    $c = lmeg_current_open_contest();
    return $c ? lmeg_contest_enter_url($c->id, $sub_id) : home_url('/');
}

add_action('init', 'lmeg_maybe_handle_contest_enter');
function lmeg_maybe_handle_contest_enter() {
    if (empty($_GET['lmeg_enter'])) return;
    global $wpdb;

    $cid    = (int) $_GET['lmeg_enter'];
    $sub_id = (int) ($_GET['u'] ?? 0);
    $exp    = (int) ($_GET['e'] ?? 0);
    $tok    = isset($_GET['t']) ? sanitize_text_field(wp_unslash($_GET['t'])) : '';

    $contest = $cid ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}lmeg_contests WHERE id = %d", $cid)) : null;
    $land    = ($contest && !empty($contest->page_url)) ? $contest->page_url : home_url('/');

    // Validate expiry + HMAC before trusting the identity in the link.
    if (!$contest || !$sub_id || $exp < time()
        || !hash_equals(lmeg_contest_enter_token($cid, $sub_id, $exp), $tok)) {
        wp_safe_redirect($land); exit;
    }
    $sub = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}" . LMEG_TABLE . " WHERE id = %d", $sub_id));
    if (!$sub) { wp_safe_redirect($land); exit; }

    // Recognize the fan everywhere (so the contest page + any form show who
    // they are), and unlock the gate for this visit.
    if (function_exists('lmeg_set_member_cookie')) lmeg_set_member_cookie($sub->id, (int) $sub->member_tier_id);
    if (function_exists('lmeg_set_cookie')) lmeg_set_cookie();

    $open = $contest->is_open && (!$contest->ends_at || $contest->ends_at > current_time('mysql'));
    if ($open) {
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->prefix}lmeg_contest_entries (contest_id, subscriber_id, entries, entered_at) VALUES (%d, %d, 1, %s)",
            $cid, $sub->id, current_time('mysql')
        ));
    }

    // Land them on the contest page (they'll see "you're in") if we know it,
    // otherwise show a built-in confirmation with their entry count + ref link.
    if (!empty($contest->page_url)) {
        wp_safe_redirect(add_query_arg('lmeg_entered', 1, $contest->page_url)); exit;
    }
    $total   = function_exists('lmeg_contest_bonus_entries') ? (int) lmeg_contest_bonus_entries($contest, $sub->id) : 1;
    $ref     = function_exists('lmeg_referral_url') ? lmeg_referral_url($sub->id) : home_url('/');
    $success = trim((string) ($contest->success_message ?? ''));
    // Use the contest's own (formatted) success message so the styling is kept;
    // fall back to a built-in confirmation if none is set.
    $inner = $success !== '' ? wpautop($success) : (
        '<h1 style="font-size:26px;">🎉 You&rsquo;re entered!</h1>'
        . '<p style="font-size:16px;"><strong>' . esc_html($contest->title) . '</strong></p>'
        . ($open ? '<p>You&rsquo;re in with <strong>' . $total . '</strong> entr' . ($total === 1 ? 'y' : 'ies') . '.</p>'
                 : '<p>This contest has closed — the winner will be announced soon.</p>')
    );
    if ($open && (!isset($contest->referral_bonus) || $contest->referral_bonus)) {
        $inner .= '<p style="margin-top:18px;">Want more chances? Every friend who joins through your link is <strong>+3 entries</strong>:<br><code style="user-select:all;">' . esc_html($ref) . '</code></p>';
    }
    $html = '<div style="max-width:480px;margin:48px auto;text-align:center;font-family:-apple-system,\'Segoe UI\',Roboto,sans-serif;">' . $inner . '</div>';
    if (function_exists('lmeg_render_full_page')) { lmeg_render_full_page($html); }
    else { wp_die($html, 'Entered', ['response' => 200]); }
    exit;
}

add_shortcode('lmeg_contest', 'lmeg_shortcode_contest');
function lmeg_shortcode_contest($atts = []) {
    $atts = shortcode_atts(['id' => 0], $atts, 'lmeg_contest');
    global $wpdb;
    $cid     = (int) $atts['id'];
    $contest = $cid ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}lmeg_contests WHERE id = %d", $cid)) : null;
    if (!$contest) return '';

    $member  = function_exists('lmeg_current_member') ? lmeg_current_member() : null;
    $entries = $wpdb->prefix . 'lmeg_contest_entries';
    $ended   = !$contest->is_open || ($contest->ends_at && $contest->ends_at < current_time('mysql'));

    $mine = null;
    if ($member) {
        $mine = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $entries WHERE contest_id = %d AND subscriber_id = %d", $cid, $member->id
        ));
    }

    // Enter (members only, once).
    if ($member && !$ended && !$mine
        && isset($_POST['lmeg_contest_id']) && (int) $_POST['lmeg_contest_id'] === $cid
        && isset($_POST['_lmeg_contest_nonce']) && wp_verify_nonce($_POST['_lmeg_contest_nonce'], 'lmeg_contest_' . $cid)) {
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO $entries (contest_id, subscriber_id, entries, entered_at) VALUES (%d, %d, 1, %s)",
            $cid, $member->id, current_time('mysql')
        ));
        $mine = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $entries WHERE contest_id = %d AND subscriber_id = %d", $cid, $member->id
        ));
    }

    $winner = null;
    if ($contest->winner_subscriber_id) {
        $winner = $wpdb->get_row($wpdb->prepare(
            "SELECT email, phone, first_name FROM {$wpdb->prefix}" . LMEG_TABLE . " WHERE id = %d",
            $contest->winner_subscriber_id
        ));
    }

    $entered = ($member && $mine);

    ob_start(); ?>
    <div class="lmeg-contest">
    <?php if ($entered && !$winner) :
        // Once someone's entered, the success message REPLACES the contest.
        $success = trim((string) ($contest->success_message ?? '')); ?>
        <?php if ($success !== '') : ?>
            <div class="lmeg-contest__success"><?php echo wpautop($success); ?></div>
        <?php else :
            $total_entries = lmeg_contest_bonus_entries($contest, $member->id); ?>
            <p class="lmeg-contest__entered">🎉 You&rsquo;re entered<?php echo $total_entries > 1 ? ' with <strong>' . (int) $total_entries . '</strong> entries' : ''; ?>!</p>
        <?php endif; ?>
        <?php if ((!isset($contest->referral_bonus) || $contest->referral_bonus) && function_exists('lmeg_referral_url')) : ?>
            <p class="lmeg-contest__note">Want more chances? Every friend who joins through your link is <strong>+3 entries</strong>:<br />
                <code class="lmeg-contest__reflink"><?php echo esc_html(lmeg_referral_url($member->id)); ?></code></p>
        <?php endif; ?>
    <?php else : ?>
        <div class="lmeg-contest__title"><?php echo esc_html($contest->title); ?></div>
        <?php if ($contest->description) : ?>
            <div class="lmeg-contest__desc"><?php echo wpautop($contest->description); ?></div>
        <?php endif; ?>

        <?php if ($winner) :
            $w = $winner->first_name ?: ($winner->email ? substr($winner->email, 0, 2) . '…@' . explode('@', $winner->email)[1] : 'a lucky fan');
        ?>
            <p class="lmeg-contest__winner">🎉 Winner: <strong><?php echo esc_html($w); ?></strong></p>
        <?php elseif ($ended) : ?>
            <p class="lmeg-contest__note">Entries are closed — winner announced soon.</p>
        <?php else : ?>
            <?php
            // Everyone enters with just an email — new fans are added to the
            // loonybin AND entered; existing fans just get entered. No sign-in.
            echo function_exists('lmeg_shortcode_signup') ? lmeg_shortcode_signup([
                'contest' => $cid,
                'phone'   => 'yes',
                'button'  => 'Enter the contest',
                'success' => 'You&rsquo;re entered! 🎉',
            ]) : ''; ?>
            <?php if ($contest->ends_at) : ?>
                <p class="lmeg-contest__note" style="margin-top:8px;">Closes <?php echo esc_html(date_i18n('M j, Y g:ia', strtotime($contest->ends_at))); ?></p>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
    </div>
    <?php return ob_get_clean();
}

/**
 * Weighted-random winner draw. Weight = stored entry + referral bonuses.
 */
function lmeg_contest_pick_winner($contest_id) {
    global $wpdb;
    $contest = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}lmeg_contests WHERE id = %d", (int) $contest_id));
    if (!$contest) return null;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT subscriber_id FROM {$wpdb->prefix}lmeg_contest_entries WHERE contest_id = %d", (int) $contest_id
    ));
    if (!$rows) return null;

    $pool = [];
    foreach ($rows as $r) {
        $w = lmeg_contest_bonus_entries($contest, (int) $r->subscriber_id);
        for ($i = 0; $i < $w; $i++) $pool[] = (int) $r->subscriber_id;
    }
    $winner = $pool[random_int(0, count($pool) - 1)];

    $wpdb->update($wpdb->prefix . 'lmeg_contests',
        ['winner_subscriber_id' => $winner, 'is_open' => 0],
        ['id' => (int) $contest_id]
    );
    return $winner;
}

/* ===========================================================================
 * ADMIN — Tour / Surveys / Contests
 * ======================================================================== */

add_action('admin_menu', function () {
    $cap = 'manage_options';
    add_submenu_page('lmeg', 'Tour',     'Tour',     $cap, 'lmeg-tour',     'lmeg_admin_tour');
    add_submenu_page('lmeg', 'Surveys',  'Surveys',  $cap, 'lmeg-surveys',  'lmeg_admin_surveys');
    add_submenu_page('lmeg', 'Contests', 'Contests', $cap, 'lmeg-contests', 'lmeg_admin_contests');
}, 20);

function lmeg_admin_tour() {
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $tbl = $wpdb->prefix . 'lmeg_tour_dates';
    $notice = '';

    if (isset($_POST['lmeg_tour_nonce']) && wp_verify_nonce($_POST['lmeg_tour_nonce'], 'lmeg_tour')) {
        $act = sanitize_text_field($_POST['lmeg_action'] ?? '');
        if ($act === 'create') {
            $wpdb->insert($tbl, [
                'show_date'            => sanitize_text_field($_POST['show_date'] ?? ''),
                'venue'                => sanitize_text_field(wp_unslash($_POST['venue'] ?? '')),
                'city'                 => sanitize_text_field(wp_unslash($_POST['city'] ?? '')),
                'region'               => sanitize_text_field(wp_unslash($_POST['region'] ?? '')),
                'ticket_url'           => esc_url_raw(wp_unslash($_POST['ticket_url'] ?? '')),
                'presale_url'          => esc_url_raw(wp_unslash($_POST['presale_url'] ?? '')),
                'presale_members_only' => !empty($_POST['presale_members_only']) ? 1 : 0,
                'status'               => in_array($_POST['status'] ?? 'onsale', ['onsale', 'soldout'], true) ? $_POST['status'] : 'onsale',
                'created_at'           => current_time('mysql'),
            ]);
            $notice = '<div class="notice notice-success"><p>Date added.</p></div>';
        } elseif ($act === 'toggle_soldout') {
            $id = (int) ($_POST['date_id'] ?? 0);
            $wpdb->query($wpdb->prepare(
                "UPDATE $tbl SET status = IF(status='soldout','onsale','soldout') WHERE id = %d", $id
            ));
        } elseif ($act === 'delete') {
            $wpdb->delete($tbl, ['id' => (int) ($_POST['date_id'] ?? 0)]);
            $notice = '<div class="notice notice-success"><p>Date removed.</p></div>';
        }
    }

    $rows = $wpdb->get_results("SELECT * FROM $tbl ORDER BY show_date ASC LIMIT 200");
    ?>
    <div class="wrap">
        <h1>Email Gate — Tour</h1>
        <?php echo $notice; ?>
        <p>Embed anywhere with <code>[lmeg_tour]</code> (upcoming) or <code>[lmeg_tour past="yes"]</code> (all). Presale links marked members-only show a 🔒 to non-members — a built-in reason to join the list.</p>

        <h2>Add a date</h2>
        <form method="post" style="margin-bottom:22px;">
            <?php wp_nonce_field('lmeg_tour', 'lmeg_tour_nonce'); ?>
            <input type="hidden" name="lmeg_action" value="create" />
            <table class="form-table" role="presentation">
                <tr><th>Date</th><td><input type="date" name="show_date" required /></td></tr>
                <tr><th>Venue</th><td><input type="text" name="venue" class="regular-text" required /></td></tr>
                <tr><th>City / Region</th><td><input type="text" name="city" placeholder="Toronto" required /> <input type="text" name="region" placeholder="ON" style="width:90px;" /></td></tr>
                <tr><th>Ticket URL</th><td><input type="url" name="ticket_url" class="regular-text" /></td></tr>
                <tr><th>Presale URL</th><td><input type="url" name="presale_url" class="regular-text" />
                    <label style="margin-left:10px;"><input type="checkbox" name="presale_members_only" value="1" checked /> members only</label></td></tr>
                <tr><th>Status</th><td><select name="status"><option value="onsale">On sale</option><option value="soldout">Sold out</option></select></td></tr>
            </table>
            <p><button type="submit" class="button button-primary">Add date</button></p>
        </form>

        <table class="widefat striped">
            <thead><tr><th>Date</th><th>Venue</th><th>City</th><th>Links</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php if (empty($rows)) : ?>
                <tr><td colspan="6">No dates yet.</td></tr>
            <?php else : foreach ($rows as $d) : ?>
                <tr>
                    <td><?php echo esc_html($d->show_date); ?></td>
                    <td><strong><?php echo esc_html($d->venue); ?></strong></td>
                    <td><?php echo esc_html($d->city . ($d->region ? ', ' . $d->region : '')); ?></td>
                    <td>
                        <?php if ($d->ticket_url) : ?><a href="<?php echo esc_url($d->ticket_url); ?>" target="_blank" rel="noopener">tickets</a><?php endif; ?>
                        <?php if ($d->presale_url) : ?> · <a href="<?php echo esc_url($d->presale_url); ?>" target="_blank" rel="noopener">presale<?php echo $d->presale_members_only ? ' 🔒' : ''; ?></a><?php endif; ?>
                    </td>
                    <td><?php echo $d->status === 'soldout' ? '<span style="color:#F87171;">Sold out</span>' : '<span style="color:#34D399;">On sale</span>'; ?></td>
                    <td>
                        <form method="post" style="display:inline;">
                            <?php wp_nonce_field('lmeg_tour', 'lmeg_tour_nonce'); ?>
                            <input type="hidden" name="date_id" value="<?php echo (int) $d->id; ?>" />
                            <button type="submit" name="lmeg_action" value="toggle_soldout" class="button">Toggle sold out</button>
                            <button type="submit" name="lmeg_action" value="delete" class="button button-link-delete" onclick="return confirm('Remove this date?');">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

function lmeg_admin_surveys() {
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $tbl = $wpdb->prefix . 'lmeg_surveys';
    $notice = '';

    if (isset($_POST['lmeg_sv_nonce']) && wp_verify_nonce($_POST['lmeg_sv_nonce'], 'lmeg_sv')) {
        $act = sanitize_text_field($_POST['lmeg_action'] ?? '');
        if ($act === 'create') {
            $question = sanitize_text_field(wp_unslash($_POST['question'] ?? ''));
            $options  = array_values(array_filter(array_map('trim', explode("\n", sanitize_textarea_field(wp_unslash($_POST['options'] ?? ''))))));
            if ($question && count($options) >= 2) {
                $wpdb->insert($tbl, [
                    'question'     => $question,
                    'options_json' => wp_json_encode($options),
                    'created_at'   => current_time('mysql'),
                ]);
                $notice = '<div class="notice notice-success"><p>Survey created — embed with <code>[lmeg_survey id=' . (int) $wpdb->insert_id . ']</code></p></div>';
            } else {
                $notice = '<div class="notice notice-error"><p>Need a question + at least 2 options (one per line).</p></div>';
            }
        } elseif ($act === 'toggle') {
            $wpdb->query($wpdb->prepare("UPDATE $tbl SET is_open = 1 - is_open WHERE id = %d", (int) ($_POST['survey_id'] ?? 0)));
        } elseif ($act === 'delete') {
            $id = (int) ($_POST['survey_id'] ?? 0);
            $wpdb->delete($tbl, ['id' => $id]);
            $wpdb->delete($wpdb->prefix . 'lmeg_survey_votes', ['survey_id' => $id]);
            $notice = '<div class="notice notice-success"><p>Survey deleted.</p></div>';
        }
    }

    $rows = $wpdb->get_results("SELECT * FROM $tbl ORDER BY id DESC LIMIT 100");
    ?>
    <div class="wrap">
        <h1>Email Gate — Surveys</h1>
        <?php echo $notice; ?>
        <p>One-question polls, members-only voting, one vote each. Embed with <code>[lmeg_survey id=N]</code> — results show after voting.</p>

        <h2>Create a survey</h2>
        <form method="post" style="margin-bottom:22px;">
            <?php wp_nonce_field('lmeg_sv', 'lmeg_sv_nonce'); ?>
            <input type="hidden" name="lmeg_action" value="create" />
            <table class="form-table" role="presentation">
                <tr><th>Question</th><td><input type="text" name="question" class="regular-text" required placeholder="Which city should we play next?" /></td></tr>
                <tr><th>Options (one per line)</th><td><textarea name="options" rows="4" class="large-text" required placeholder="Toronto&#10;Montreal&#10;Vancouver"></textarea></td></tr>
            </table>
            <p><button type="submit" class="button button-primary">Create survey</button></p>
        </form>

        <table class="widefat striped">
            <thead><tr><th>Survey</th><th>Shortcode</th><th>Votes</th><th>Results</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php if (empty($rows)) : ?>
                <tr><td colspan="6">No surveys yet.</td></tr>
            <?php else : foreach ($rows as $s) :
                $opts    = json_decode($s->options_json, true) ?: [];
                $tallies = $wpdb->get_results($wpdb->prepare(
                    "SELECT option_idx, COUNT(*) AS n FROM {$wpdb->prefix}lmeg_survey_votes WHERE survey_id = %d GROUP BY option_idx", $s->id
                ));
                $by_idx = [];
                foreach ((array) $tallies as $t) $by_idx[(int) $t->option_idx] = (int) $t->n;
                $total = array_sum($by_idx);
                $summary = [];
                foreach ($opts as $i => $o) $summary[] = esc_html($o) . ': <strong>' . (int) ($by_idx[$i] ?? 0) . '</strong>';
            ?>
                <tr>
                    <td><strong><?php echo esc_html($s->question); ?></strong></td>
                    <td><code>[lmeg_survey id=<?php echo (int) $s->id; ?>]</code></td>
                    <td><?php echo (int) $total; ?></td>
                    <td><?php echo implode(' · ', $summary); ?></td>
                    <td><?php echo $s->is_open ? '<span style="color:#34D399;">Open</span>' : '<span style="color:#F87171;">Closed</span>'; ?></td>
                    <td>
                        <form method="post" style="display:inline;">
                            <?php wp_nonce_field('lmeg_sv', 'lmeg_sv_nonce'); ?>
                            <input type="hidden" name="survey_id" value="<?php echo (int) $s->id; ?>" />
                            <button type="submit" name="lmeg_action" value="toggle" class="button"><?php echo $s->is_open ? 'Close' : 'Reopen'; ?></button>
                            <button type="submit" name="lmeg_action" value="delete" class="button button-link-delete" onclick="return confirm('Delete survey + votes?');">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

function lmeg_admin_contests() {
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $tbl = $wpdb->prefix . 'lmeg_contests';
    $notice = '';

    // Entrants detail view — the full list of who entered a contest.
    if (!empty($_GET['contest'])) {
        lmeg_admin_contest_entrants((int) $_GET['contest']);
        return;
    }

    if (isset($_POST['lmeg_ct_nonce']) && wp_verify_nonce($_POST['lmeg_ct_nonce'], 'lmeg_ct')) {
        $act = sanitize_text_field($_POST['lmeg_action'] ?? '');
        if ($act === 'create') {
            $title = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
            if ($title) {
                $ends = sanitize_text_field($_POST['ends_at'] ?? '');
                $wpdb->insert($tbl, [
                    'title'           => $title,
                    'description'     => wp_kses_post(wp_unslash($_POST['description'] ?? '')),
                    'ends_at'         => $ends ? date('Y-m-d H:i:s', strtotime(str_replace('T', ' ', $ends))) : null,
                    'page_url'        => esc_url_raw(wp_unslash($_POST['page_url'] ?? '')) ?: null,
                    'referral_bonus'  => !empty($_POST['referral_bonus']) ? 1 : 0,
                    'success_message' => wp_kses_post(wp_unslash($_POST['success_message'] ?? '')) ?: null,
                    'created_at'      => current_time('mysql'),
                ]);
                $notice = '<div class="notice notice-success"><p>Contest created — embed with <code>[lmeg_contest id=' . (int) $wpdb->insert_id . ']</code></p></div>';
            }
        } elseif ($act === 'update') {
            $id    = (int) ($_POST['contest_id'] ?? 0);
            $title = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
            if ($id && $title) {
                $ends = sanitize_text_field($_POST['ends_at'] ?? '');
                $wpdb->update($tbl, [
                    'title'           => $title,
                    'description'     => wp_kses_post(wp_unslash($_POST['description'] ?? '')),
                    'ends_at'         => $ends ? date('Y-m-d H:i:s', strtotime(str_replace('T', ' ', $ends))) : null,
                    'page_url'        => esc_url_raw(wp_unslash($_POST['page_url'] ?? '')) ?: null,
                    'is_open'         => !empty($_POST['is_open']) ? 1 : 0,
                    'referral_bonus'  => !empty($_POST['referral_bonus']) ? 1 : 0,
                    'success_message' => wp_kses_post(wp_unslash($_POST['success_message'] ?? '')) ?: null,
                ], ['id' => $id]);
                $notice = '<div class="notice notice-success"><p>Contest updated.</p></div>';
            }
        } elseif ($act === 'pick_winner') {
            $winner = lmeg_contest_pick_winner((int) ($_POST['contest_id'] ?? 0));
            $notice = $winner
                ? '<div class="notice notice-success"><p>Winner drawn! Fan #' . (int) $winner . ' — see the contest row for who it is.</p></div>'
                : '<div class="notice notice-error"><p>No entries to draw from.</p></div>';
        } elseif ($act === 'delete') {
            $id = (int) ($_POST['contest_id'] ?? 0);
            $wpdb->delete($tbl, ['id' => $id]);
            $wpdb->delete($wpdb->prefix . 'lmeg_contest_entries', ['contest_id' => $id]);
            $notice = '<div class="notice notice-success"><p>Contest deleted.</p></div>';
        }
    }

    $rows    = $wpdb->get_results("SELECT * FROM $tbl ORDER BY id DESC LIMIT 100");
    $active  = function_exists('lmeg_current_open_contest') ? lmeg_current_open_contest() : null;
    $editing = !empty($_GET['edit']) ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $tbl WHERE id = %d", (int) $_GET['edit'])) : null;
    ?>
    <div class="wrap">
        <h1>Email Gate — Contests</h1>
        <?php echo $notice; ?>
        <p>Members enter with one click; every friend they refer during the contest is <strong>+3 entries</strong>. Embed with <code>[lmeg_contest id=N]</code>. Winner is drawn weighted by entries.</p>
        <p style="background:#f6f1ea;border:1px solid #e6ddd2;border-radius:8px;padding:10px 14px;max-width:820px;">
            <strong>One-tap entry for your list:</strong> put a contest tag in an email or SMS broadcast and each recipient gets a personalized link that signs them in and enters them with a single tap — no typing, no login.<br>
            &bull; <code>{contest_link}</code> &mdash; always the <strong>newest open</strong> contest<?php echo $active ? ' (right now: <strong>' . esc_html($active->title) . '</strong>)' : ' (none open right now)'; ?>.<br>
            &bull; <code>{contest_link:ID}</code> &mdash; a <strong>specific</strong> contest, using its ID from the table below (so you always know which one it is).
        </p>

        <h2 id="lmeg-ct-form"><?php echo $editing ? 'Edit contest' : 'Create a contest'; ?></h2>
        <form method="post" style="margin-bottom:22px;">
            <?php wp_nonce_field('lmeg_ct', 'lmeg_ct_nonce'); ?>
            <input type="hidden" name="lmeg_action" value="<?php echo $editing ? 'update' : 'create'; ?>" />
            <?php if ($editing) : ?><input type="hidden" name="contest_id" value="<?php echo (int) $editing->id; ?>" /><?php endif; ?>
            <table class="form-table" role="presentation">
                <tr><th>Title</th><td><input type="text" name="title" class="regular-text" required value="<?php echo esc_attr($editing->title ?? ''); ?>" placeholder="Win signed vinyl" /></td></tr>
                <tr><th>Description</th><td>
                    <?php wp_editor($editing->description ?? '', 'lmeg_contest_desc', [
                        'textarea_name' => 'description',
                        'textarea_rows' => 5,
                        'media_buttons' => false,
                        'teeny'         => true,
                    ]); ?>
                    <p class="description">Bold, italics, links, and line breaks are supported.</p>
                </td></tr>
                <tr><th>Ends</th><td><input type="datetime-local" name="ends_at" value="<?php echo ($editing && $editing->ends_at) ? esc_attr(date('Y-m-d\TH:i', strtotime($editing->ends_at))) : ''; ?>" /> <span class="description">optional</span></td></tr>
                <tr><th>Contest page URL</th><td><input type="url" name="page_url" class="regular-text" value="<?php echo esc_attr($editing->page_url ?? ''); ?>" placeholder="https://loonymoonchild.com/contest/" /> <span class="description">optional — where you embedded <code>[lmeg_contest]</code>; one-tap links land here</span></td></tr>
                <tr><th>Referral bonus</th><td><label><input type="checkbox" name="referral_bonus" value="1" <?php checked((int) ($editing->referral_bonus ?? 1), 1); ?> /> Give <strong>+3 entries</strong> per friend referred, and show the &ldquo;want more chances&rdquo; prompt</label> <span class="description">uncheck for a straight one-entry-per-fan contest</span></td></tr>
                <tr><th>Success message</th><td>
                    <?php wp_editor($editing->success_message ?? '', 'lmeg_contest_success', [
                        'textarea_name' => 'success_message',
                        'textarea_rows' => 3,
                        'media_buttons' => false,
                        'teeny'         => true,
                    ]); ?>
                    <p class="description">Shown after someone enters — it <strong>replaces the contest</strong> on the page. Leave blank for a default &ldquo;You&rsquo;re entered! 🎉&rdquo;.</p>
                </td></tr>
                <?php if ($editing) : ?>
                <tr><th>Status</th><td><label><input type="checkbox" name="is_open" value="1" <?php checked((int) $editing->is_open, 1); ?> /> Open for entries</label> <span class="description">uncheck to close entries without drawing a winner</span></td></tr>
                <?php endif; ?>
            </table>
            <p>
                <button type="submit" class="button button-primary"><?php echo $editing ? 'Save changes' : 'Create contest'; ?></button>
                <?php if ($editing) : ?><a href="<?php echo esc_url(admin_url('admin.php?page=lmeg-contests')); ?>" style="margin-left:8px;">Cancel</a><?php endif; ?>
            </p>
        </form>

        <table class="widefat striped">
            <thead><tr><th>Contest</th><th>Shortcode</th><th>Entrants</th><th>Ends</th><th>Winner</th><th></th></tr></thead>
            <tbody>
            <?php if (empty($rows)) : ?>
                <tr><td colspan="6">No contests yet.</td></tr>
            <?php else : foreach ($rows as $c) :
                $n = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}lmeg_contest_entries WHERE contest_id = %d", $c->id
                ));
                $winner = $c->winner_subscriber_id
                    ? $wpdb->get_row($wpdb->prepare("SELECT id, email, phone FROM {$wpdb->prefix}" . LMEG_TABLE . " WHERE id = %d", $c->winner_subscriber_id))
                    : null;
            ?>
                <tr>
                    <td><strong><?php echo esc_html($c->title); ?></strong><br><span class="description">ID <?php echo (int) $c->id; ?></span></td>
                    <td>
                        <code>[lmeg_contest id=<?php echo (int) $c->id; ?>]</code><br>
                        <code style="user-select:all;">{contest_link:<?php echo (int) $c->id; ?>}</code>
                        <?php if ($active && (int) $active->id === (int) $c->id) : ?><br><span class="description" style="color:#1a6f1a;">&larr; also what plain <code>{contest_link}</code> points to now</span><?php endif; ?>
                    </td>
                    <td><?php if ($n) : ?><a href="<?php echo esc_url(add_query_arg(['page' => 'lmeg-contests', 'contest' => (int) $c->id], admin_url('admin.php'))); ?>"><?php echo $n; ?> &rsaquo; view</a><?php else : ?>0<?php endif; ?></td>
                    <td><?php echo esc_html($c->ends_at ?: '—'); ?></td>
                    <td><?php echo $winner
                        ? '<a href="' . esc_url(add_query_arg(['page' => 'lmeg', 'fan' => (int) $winner->id], admin_url('admin.php'))) . '">' . esc_html($winner->email ?: $winner->phone) . '</a>'
                        : '—'; ?></td>
                    <td>
                        <a href="<?php echo esc_url(add_query_arg(['page' => 'lmeg-contests', 'edit' => (int) $c->id], admin_url('admin.php'))); ?>#lmeg-ct-form" class="button">Edit</a>
                        <form method="post" style="display:inline;">
                            <?php wp_nonce_field('lmeg_ct', 'lmeg_ct_nonce'); ?>
                            <input type="hidden" name="contest_id" value="<?php echo (int) $c->id; ?>" />
                            <?php if (!$c->winner_subscriber_id) : ?>
                                <button type="submit" name="lmeg_action" value="pick_winner" class="button button-primary" onclick="return confirm('Draw the winner now? This closes the contest.');">Draw winner</button>
                            <?php endif; ?>
                            <button type="submit" name="lmeg_action" value="delete" class="button button-link-delete" onclick="return confirm('Delete contest + entries?');">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

/**
 * Full list of who entered a contest, ranked by total (weighted) entries —
 * base entry + referral bonuses. Each entrant links to their fan profile.
 */
function lmeg_admin_contest_entrants($contest_id) {
    global $wpdb;
    $contest = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}lmeg_contests WHERE id = %d", (int) $contest_id));
    if (!$contest) { echo '<div class="wrap"><h1>Contest not found.</h1></div>'; return; }

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT e.subscriber_id, e.entries AS base_entries, e.entered_at,
                s.email, s.phone, s.first_name, s.country
         FROM {$wpdb->prefix}lmeg_contest_entries e
         JOIN {$wpdb->prefix}" . LMEG_TABLE . " s ON s.id = e.subscriber_id
         WHERE e.contest_id = %d
         ORDER BY e.entered_at ASC", (int) $contest_id
    ));

    $list = []; $total_weight = 0;
    foreach ($rows as $r) {
        $total = function_exists('lmeg_contest_bonus_entries')
            ? (int) lmeg_contest_bonus_entries($contest, $r->subscriber_id)
            : (int) $r->base_entries;
        $total_weight += $total;
        $list[] = ['r' => $r, 'total' => $total];
    }
    usort($list, function ($a, $b) { return $b['total'] - $a['total']; });

    $back   = admin_url('admin.php?page=lmeg-contests');
    $export = wp_nonce_url(admin_url('admin-post.php?action=lmeg_export_contest&contest=' . (int) $contest_id), 'lmeg_export_contest');
    ?>
    <div class="wrap">
        <p><a href="<?php echo esc_url($back); ?>">&larr; All contests</a></p>
        <h1>Entrants &mdash; <?php echo esc_html($contest->title); ?></h1>
        <p>
            <strong><?php echo number_format_i18n(count($list)); ?></strong> entrant<?php echo count($list) === 1 ? '' : 's'; ?> &middot;
            <strong><?php echo number_format_i18n($total_weight); ?></strong> total entries (base + referral bonuses)
            <?php if ($list) : ?><a href="<?php echo esc_url($export); ?>" class="button" style="margin-left:8px;">Export entrants CSV</a><?php endif; ?>
        </p>
        <table class="widefat striped" style="max-width:840px;">
            <thead><tr><th>#</th><th>Fan</th><th>Country</th><th>Entries</th><th>Entered</th></tr></thead>
            <tbody>
            <?php if (empty($list)) : ?>
                <tr><td colspan="5">No entrants yet. They enter from the <code>[lmeg_contest id=<?php echo (int) $contest_id; ?>]</code> shortcode (must be a signed-in subscriber).</td></tr>
            <?php else : $i = 0; foreach ($list as $item) : $r = $item['r']; $i++;
                $name      = $r->first_name ?: ($r->email ?: $r->phone);
                $is_winner = ((int) $contest->winner_subscriber_id === (int) $r->subscriber_id);
            ?>
                <tr>
                    <td><?php echo (int) $i; ?></td>
                    <td><?php echo $is_winner ? '🎉 ' : ''; ?><a href="<?php echo esc_url(add_query_arg(['page' => 'lmeg', 'fan' => (int) $r->subscriber_id], admin_url('admin.php'))); ?>"><?php echo esc_html($name); ?></a></td>
                    <td><?php echo $r->country ? esc_html((function_exists('lmeg_flag_emoji') ? lmeg_flag_emoji($r->country) . ' ' : '') . $r->country) : '—'; ?></td>
                    <td><strong><?php echo (int) $item['total']; ?></strong></td>
                    <td style="white-space:nowrap;"><?php echo esc_html($r->entered_at); ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        <p class="description">Ranked by total entries (base + <strong>+3 per fan they referred</strong> during the contest) — the same weighting the winner draw uses.</p>
    </div>
    <?php
}

add_action('admin_post_lmeg_export_contest', 'lmeg_export_contest_csv');
function lmeg_export_contest_csv() {
    if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
    check_admin_referer('lmeg_export_contest');
    global $wpdb;
    $cid = (int) ($_GET['contest'] ?? 0);
    $contest = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}lmeg_contests WHERE id = %d", $cid));
    if (!$contest) wp_die('Contest not found.');

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT e.subscriber_id, e.entries, e.entered_at, s.email, s.phone, s.first_name, s.country
         FROM {$wpdb->prefix}lmeg_contest_entries e
         JOIN {$wpdb->prefix}" . LMEG_TABLE . " s ON s.id = e.subscriber_id
         WHERE e.contest_id = %d ORDER BY e.entered_at ASC", $cid
    ));

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="contest-' . $cid . '-entrants.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['email', 'phone', 'first_name', 'country', 'total_entries', 'entered_at']);
    foreach ($rows as $r) {
        $total = function_exists('lmeg_contest_bonus_entries')
            ? lmeg_contest_bonus_entries($contest, $r->subscriber_id) : $r->entries;
        fputcsv($out, [$r->email, $r->phone, $r->first_name, $r->country, $total, $r->entered_at]);
    }
    fclose($out);
    exit;
}
