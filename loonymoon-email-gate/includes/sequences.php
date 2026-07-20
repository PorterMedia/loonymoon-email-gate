<?php
/**
 * Drip sequences — multi-step time-based emails/SMS triggered by tag.
 *
 * A sequence has an ordered list of steps (each with delay_days, subject,
 * body_email, body_sms). When a subscriber gets the trigger tag, an
 * enrollment row is created scheduled `delay_days` in the future for step 1.
 * The cron tick sends the due step and advances `current_position` +
 * `next_send_at` until the sequence is exhausted.
 */

if (!defined('ABSPATH')) {
    exit;
}

/* ---------------------------------------------------------------------------
 * Sequence + step CRUD helpers (thin — most of the work is in admin.php)
 * ------------------------------------------------------------------------- */

function lmeg_all_sequences() {
    global $wpdb;
    return $wpdb->get_results("SELECT * FROM {$wpdb->prefix}lmeg_sequences ORDER BY name ASC");
}

function lmeg_sequence($id) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}lmeg_sequences WHERE id = %d",
        (int) $id
    ));
}

function lmeg_sequence_steps($sequence_id) {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}lmeg_sequence_steps WHERE sequence_id = %d ORDER BY position ASC",
        (int) $sequence_id
    ));
}

/* ---------------------------------------------------------------------------
 * Enrollment — auto-enroll subscribers when they get the trigger tag
 * ------------------------------------------------------------------------- */

add_action('lmeg_tag_attached', 'lmeg_enroll_on_tag', 10, 2);
function lmeg_enroll_on_tag($subscriber_id, $tag_id) {
    global $wpdb;
    $seqs = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}lmeg_sequences WHERE trigger_tag_id = %d AND is_active = 1",
        $tag_id
    ));
    foreach ($seqs as $seq) {
        lmeg_enroll_subscriber($subscriber_id, $seq->id);
    }
}

function lmeg_enroll_subscriber($subscriber_id, $sequence_id) {
    global $wpdb;
    $steps = lmeg_sequence_steps($sequence_id);
    if (!$steps) return false;

    $first = $steps[0];
    $next_send = strtotime(current_time('mysql')) + ((int) $first->delay_days) * DAY_IN_SECONDS;

    $wpdb->query($wpdb->prepare(
        "INSERT IGNORE INTO {$wpdb->prefix}lmeg_sequence_enrollments
            (subscriber_id, sequence_id, current_position, next_send_at, status, enrolled_at)
         VALUES (%d, %d, %d, %s, 'active', %s)",
        $subscriber_id,
        $sequence_id,
        (int) $first->position,
        date('Y-m-d H:i:s', $next_send),
        current_time('mysql')
    ));
    return true;
}

/* ---------------------------------------------------------------------------
 * Cron tick — process any enrollments whose next_send_at has passed.
 * ------------------------------------------------------------------------- */

add_action('lmeg_broadcast_tick', 'lmeg_process_sequence_tick', 20);
function lmeg_process_sequence_tick() {
    global $wpdb;
    $enr_tbl  = $wpdb->prefix . 'lmeg_sequence_enrollments';
    $step_tbl = $wpdb->prefix . 'lmeg_sequence_steps';

    $now  = current_time('mysql');
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $enr_tbl WHERE status = 'active' AND next_send_at IS NOT NULL AND next_send_at <= %s ORDER BY next_send_at ASC LIMIT %d",
        $now, lmeg_batch_size()
    ));
    if (!$rows) return;

    foreach ($rows as $enr) {
        $step = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $step_tbl WHERE sequence_id = %d AND position = %d LIMIT 1",
            $enr->sequence_id, $enr->current_position
        ));
        if (!$step) {
            $wpdb->update($enr_tbl, [
                'status'       => 'completed',
                'completed_at' => $now,
                'next_send_at' => null,
            ], ['id' => $enr->id]);
            continue;
        }

        $sub = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}" . LMEG_TABLE . " WHERE id = %d",
            $enr->subscriber_id
        ));
        if (!$sub || $sub->unsubscribed_at) {
            // Silently cancel — respect the opt-out.
            $wpdb->update($enr_tbl, [
                'status'       => 'cancelled',
                'completed_at' => $now,
                'next_send_at' => null,
            ], ['id' => $enr->id]);
            continue;
        }

        lmeg_send_sequence_step($sub, $step);

        // Advance to next step or complete.
        $next_step = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $step_tbl WHERE sequence_id = %d AND position > %d ORDER BY position ASC LIMIT 1",
            $enr->sequence_id, $enr->current_position
        ));
        if ($next_step) {
            $wpdb->update($enr_tbl, [
                'current_position' => (int) $next_step->position,
                'next_send_at'     => date('Y-m-d H:i:s', strtotime($now) + ((int) $next_step->delay_days) * DAY_IN_SECONDS),
            ], ['id' => $enr->id]);
        } else {
            $wpdb->update($enr_tbl, [
                'status'       => 'completed',
                'completed_at' => $now,
                'next_send_at' => null,
            ], ['id' => $enr->id]);
        }
    }
}

/**
 * Send a single sequence step to a single subscriber. Best-effort — errors
 * are logged via error_log; we don't halt the cron tick because one step
 * failed for one recipient.
 */
function lmeg_send_sequence_step($sub, $step) {
    global $wpdb;
    if ($sub->contact_type === 'email' && !empty($sub->email) && !empty($step->body_email)) {
        $subject   = lmeg_render_merge_tags((string) $step->subject, $sub);
        $body      = lmeg_render_merge_tags((string) $step->body_email, $sub);
        $unsub_url = lmeg_unsub_url((int) $sub->id, $sub->email);
        list($text, $html) = lmeg_build_email_with_footer($body, $unsub_url);
        // Track opens/clicks per step (source='sequence', ref=step id).
        if (function_exists('lmeg_apply_tracking')) {
            $html = lmeg_apply_tracking($html, 0, (int) $sub->id, 'sequence', (int) $step->id);
        }
        $r = lmeg_email_send($sub->email, $subject ?: '(no subject)', $text, $html);
        if (is_wp_error($r)) {
            error_log('[lmeg sequence] email send failed: ' . $r->get_error_message());
        } else {
            $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}lmeg_sequence_steps SET sends = sends + 1 WHERE id = %d", (int) $step->id));
        }
    } elseif ($sub->contact_type === 'phone' && !empty($sub->phone) && !empty($step->body_sms)) {
        $body = lmeg_render_merge_tags((string) $step->body_sms, $sub);
        $r    = lmeg_twilio_send($sub->phone, $body);
        if (is_wp_error($r)) {
            error_log('[lmeg sequence] sms send failed: ' . $r->get_error_message());
        } else {
            $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}lmeg_sequence_steps SET sends = sends + 1 WHERE id = %d", (int) $step->id));
        }
    }
}
