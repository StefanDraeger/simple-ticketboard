<?php
/*
Plugin Name: Simple Ticketboard
Description: Einfaches Support-Ticketsystem mit Ticketliste über Shortcode.
Version: 1.0
Author: Stefan Draeger
*/

defined('ABSPATH') or die('No script kiddies please!');

// CPT registrieren
function stb_register_support_ticket_cpt() {
    $args = array(
        'label' => 'Support-Tickets',
        'public' => true,
        'has_archive' => false,
        'rewrite' => array('slug' => 'support-ticket'),
        'show_in_rest' => true,
        'supports' => array('title', 'editor', 'custom-fields'),
        'capability_type' => 'post',
        'publicly_queryable' => false,
        'menu_icon' => 'dashicons-tickets-alt',
    );
    register_post_type('support_ticket', $args);
}
add_action('init', 'stb_register_support_ticket_cpt');

function stb_add_ticket_status_column($columns) {
    $columns['ticket_done'] = 'Erledigt';
    return $columns;
}
add_filter('manage_support_ticket_posts_columns', 'stb_add_ticket_status_column');

function stb_show_ticket_status_column($column, $post_id) {
    if ($column === 'ticket_done') {
        $done = get_post_meta($post_id, 'ticket_done', true);
        echo $done === '1' ? '✅ Ja' : '❌ Nein';
    }
}
add_action('manage_support_ticket_posts_custom_column', 'stb_show_ticket_status_column', 10, 2);

function stb_sortable_ticket_columns($columns) {
    $columns['ticket_done'] = 'ticket_done';
    return $columns;
}
add_filter('manage_edit-support_ticket_sortable_columns', 'stb_sortable_ticket_columns');

// Sortierfunktion
function stb_ticket_orderby_meta($query) {
    if (!is_admin() || !$query->is_main_query()) return;

    if ($query->get('orderby') === 'ticket_done') {
        $query->set('meta_key', 'ticket_done');
        $query->set('orderby', 'meta_value');
    }
}
add_action('pre_get_posts', 'stb_ticket_orderby_meta');

// Shortcode zur Anzeige der Liste
function stb_shortcode_ticket_liste() {
	$args = array(
    'post_type' => 'support_ticket',
    'posts_per_page' => -1,
    'orderby' => 'date',
    'order' => 'DESC',
    'meta_query' => array(
        array(
            'key' => 'ticket_done',
            'compare' => 'NOT EXISTS',
        )
    )
	);

    $tickets = get_posts($args);
    if (empty($tickets)) return '<p>Derzeit keine Tickets vorhanden.</p>';
	
	$output = '<ul class="support-ticket-liste">';
	foreach ($tickets as $ticket) {
		$raw_title = $ticket->post_title;
		$title = esc_html(mb_strlen($raw_title) > 47 ? mb_substr($raw_title, 0, 47) . '…' : $raw_title);
        $date = get_the_date('d.m.Y', $ticket->ID);
		$done = get_post_meta($ticket->ID, 'ticket_done', true);
		$done_class = $done === '1' ? ' erledigt' : '';
		$done_prefix = $done === '1' ? '✅' : '📌';
		$blog_note = get_post_meta($ticket->ID, 'ticket_blog_note', true);
		$status_suffix = '';

		$status_suffix = '';
		if ($blog_note === 'in_arbeit') {
    		$status_suffix = ' <span class="status">✍️ Beitrag in Arbeit</span>';
		} elseif ($blog_note === 'veröffentlicht') {
    		$status_suffix = ' <span class="status">✅ Beitrag veröffentlicht</span>';
		}

		$output .= "<li class='{$done_class}'>";
		$output .= "<div class='ticket-title'>{$done_prefix} <strong>{$date}</strong> – {$title}</div>";
		if ($status_suffix !== '') {
    		$output .= $status_suffix;
		}
		$output .= "</li>";
	}
	$output .= '</ul>';

    return $output;
}
add_shortcode('ticket_liste', 'stb_shortcode_ticket_liste');

// Optional: Basic CSS
function stb_enqueue_plugin_styles() {
    wp_enqueue_style(
        'simple-ticketboard-style',
        plugin_dir_url(__FILE__) . 'style.css',
        array(),
        '1.0'
    );
}
add_action('wp_enqueue_scripts', 'stb_enqueue_plugin_styles');

function stb_ticket_form_shortcode() {
    ob_start();
    ?>
    <form method="post" action="" class="ticket-form">
        <p><label>Dein Name (optional)<br>
            <input type="text" name="stb_name" /></label></p>

        <p><label>Deine E-Mail-Adresse<br>
            <input type="email" name="stb_email" required /></label></p>

        <p><label>Betreff<br>
            <input type="text" name="stb_subject" required /></label></p>

        <p><label>Nachricht<br>
            <textarea name="stb_message" rows="5" required></textarea></label></p>

        <p><label>
            <input type="checkbox" name="stb_privacy" value="1" required />
            Ich habe die <a href="https://draeger-it.blog/datenschutzerklaerung/" target="_blank" rel="noopener">Datenschutzerklärung</a> gelesen und akzeptiert.
        </label></p>

        <p><label>Sicherheitsfrage: Was ist 3 + 4?<br>
            <input type="text" name="stb_captcha" required /></label></p>

        <p><button type="submit" name="stb_submit">Absenden</button></p>
    </form>
    <?php
    return ob_get_clean();
}
add_shortcode('ticket_form', 'stb_ticket_form_shortcode');

function stb_handle_ticket_submission() {
    if (!isset($_POST['stb_submit'])) return;

    // Datenschutz akzeptiert?
    if (empty($_POST['stb_privacy'])) {
        return; // alternativ: Fehler anzeigen
    }

    // Captcha prüfen (Erwartet: "7")
    if (trim($_POST['stb_captcha']) !== '7') {
        return; // alternativ: Fehlermeldung anzeigen
    }

    // Daten sicher holen
    $name = sanitize_text_field($_POST['stb_name'] ?? '');
    $email = sanitize_email($_POST['stb_email'] ?? '');
    $subject = sanitize_text_field($_POST['stb_subject'] ?? '');
    $message = sanitize_textarea_field($_POST['stb_message'] ?? '');

    // Neuen Post erstellen
    $post_id = wp_insert_post(array(
        'post_type' => 'support_ticket',
        'post_title' => $subject,
        'post_content' => $message,
        'post_status' => 'publish'
    ));

    if ($post_id) {
        update_post_meta($post_id, 'email_address', $email);
        update_post_meta($post_id, 'name', $name);

        // E-Mail senden
        $to = get_option('admin_email');
        $subject_line = "📩 Neue Support-Anfrage: " . $subject;
        $body = "Es wurde ein neues Support-Ticket eingereicht:\n\n"
              . "Name: $name\n"
              . "E-Mail: $email\n"
              . "Betreff: $subject\n"
              . "Nachricht:\n$message\n\n"
              . "Ticket ansehen: " . admin_url("post.php?post=$post_id&action=edit");

        wp_mail($to, $subject_line, $body);
    }
}

function stb_add_status_metabox() {
    add_meta_box(
        'stb_ticket_status',
        'Ticket-Status',
        'stb_render_status_metabox',
        'support_ticket',
        'side',
        'default'
    );
}
add_action('add_meta_boxes', 'stb_add_status_metabox');

function stb_render_status_metabox($post) {
    $value = get_post_meta($post->ID, 'ticket_done', true);
    wp_nonce_field('stb_save_ticket_status', 'stb_ticket_status_nonce');
    ?>
    <label>
        <input type="checkbox" name="ticket_done" value="1" <?php checked($value, '1'); ?> />
        Ticket ist erledigt
    </label>
    <?php
}

function stb_save_ticket_status($post_id) {
    if (!isset($_POST['stb_ticket_status_nonce']) || 
        !wp_verify_nonce($_POST['stb_ticket_status_nonce'], 'stb_save_ticket_status')) {
        return;
    }

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;

    if (isset($_POST['ticket_done'])) {
        update_post_meta($post_id, 'ticket_done', '1');
    } else {
        delete_post_meta($post_id, 'ticket_done');
    }
}
add_action('save_post_support_ticket', 'stb_save_ticket_status');

// Metabox hinzufügen (nur für Admins)
function stb_add_blog_note_metabox() {
    if (!current_user_can('manage_options')) return;

    add_meta_box(
        'stb_blog_note_metabox',         // ID der Box
        'Geplante Veröffentlichung?',    // Titel
        'stb_render_blog_note_metabox', // Callback-Funktion
        'support_ticket',                // Beitragstyp
        'side',                          // Position (Seitenleiste)
        'default'                        // Priorität
    );
}
add_action('add_meta_boxes', 'stb_add_blog_note_metabox');

// Inhalt der Metabox (Dropdown)
function stb_render_blog_note_metabox($post) {
    $value = get_post_meta($post->ID, 'ticket_blog_note', true);
    wp_nonce_field('stb_save_blog_note', 'stb_blog_note_nonce');

    $options = array(
        '' => '❌ Keine Idee',
        'idee' => '💡 Idee für Beitrag',
        'in_arbeit' => '✍️ Beitrag in Arbeit',
        'veröffentlicht' => '✅ Beitrag veröffentlicht',
    );

    echo '<label for="ticket_blog_note">Status wählen:</label><br>';
    echo '<select name="ticket_blog_note" id="ticket_blog_note" style="width:100%;">';
    foreach ($options as $key => $label) {
        $selected = selected($value, $key, false);
        echo "<option value='" . esc_attr($key) . "' $selected>" . esc_html($label) . "</option>";
    }
    echo '</select>';
}

function stb_save_blog_note($post_id) {
    if (!isset($_POST['stb_blog_note_nonce']) ||
        !wp_verify_nonce($_POST['stb_blog_note_nonce'], 'stb_save_blog_note')) {
        return;
    }

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;

    if (current_user_can('manage_options')) {
        $note = sanitize_text_field($_POST['ticket_blog_note'] ?? '');
        update_post_meta($post_id, 'ticket_blog_note', $note);
    }
}
add_action('save_post_support_ticket', 'stb_save_blog_note');

// Neue Spalte zur Tabelle hinzufügen
function stb_add_blog_note_column($columns) {
    $columns['ticket_blog_note'] = 'Blog-Status';
    return $columns;
}
add_filter('manage_support_ticket_posts_columns', 'stb_add_blog_note_column');

// Spalteninhalt befüllen
function stb_show_blog_note_column($column, $post_id) {
    if ($column === 'ticket_blog_note') {
        $value = get_post_meta($post_id, 'ticket_blog_note', true);
        switch ($value) {
            case 'idee':
                echo '💡 Idee';
                break;
            case 'in_arbeit':
                echo '✍️ In Arbeit';
                break;
            case 'veröffentlicht':
                echo '✅ Veröffentlicht';
                break;
            default:
                echo '❌ Keine Idee';
        }
    }
}
add_action('manage_support_ticket_posts_custom_column', 'stb_show_blog_note_column', 10, 2);

function stb_add_custom_row_attributes($classes, $class, $post_id) {
    if (get_post_type($post_id) !== 'support_ticket') return $classes;

    $done = get_post_meta($post_id, 'ticket_done', true);
    $note = get_post_meta($post_id, 'ticket_blog_note', true);

    $classes[] = 'ticket-done-' . ($done ? '1' : '0');
    $classes[] = 'ticket-blog-note-' . sanitize_html_class($note);

    return $classes;
}
add_filter('post_class', 'stb_add_custom_row_attributes', 10, 3);

function stb_quick_edit_script_output() {
    global $typenow;
    if ($typenow !== 'support_ticket') return;

    ?>
    <script>
        jQuery(document).ready(function ($) {
            const $inlineEditor = inlineEditPost.edit;

            inlineEditPost.edit = function (postId) {
                $inlineEditor.apply(this, arguments);

                // ID holen
                var post_id = 0;
                if (typeof(postId) === 'object') {
                    post_id = parseInt(this.getId(postId));
                } else {
                    post_id = parseInt(postId);
                }

                const row = $('#post-' + post_id);
                const isDone = row.hasClass('ticket-done-1');
                const blogNote = row.attr('class').match(/ticket-blog-note-([^\s]+)/);
                const blogNoteValue = blogNote ? blogNote[1] : '';

                const editRow = $('#edit-' + post_id);

                // Checkbox setzen
                editRow.find('input[name="ticket_done"]').prop('checked', isDone);

                // Dropdown setzen
                editRow.find('select[name="ticket_blog_note"]').val(blogNoteValue);
            };
        });
    </script>
    <?php
}
add_action('admin_footer-edit.php', 'stb_quick_edit_script_output');

function stb_add_quick_edit_fields($column_name, $post_type) {
    if ($post_type !== 'support_ticket') return;

    if ($column_name === 'ticket_done' || $column_name === 'ticket_blog_note') {
        ?>
        <fieldset class="inline-edit-col-right">
            <div class="inline-edit-col">
                <?php if ($column_name === 'ticket_done') : ?>
                    <label class="alignleft">
                        <input type="checkbox" name="ticket_done" value="1" />
                        <span class="checkbox-title">Erledigt</span>
                    </label>
                <?php endif; ?>

                <?php if ($column_name === 'ticket_blog_note') : ?>
                    <label class="alignleft" style="margin-top:10px; display:block;">
                        <span class="title">Blog-Status</span>
                        <select name="ticket_blog_note">
                            <option value="">❌ Keine Idee</option>
                            <option value="idee">💡 Idee für Beitrag</option>
                            <option value="in_arbeit">✍️ Beitrag in Arbeit</option>
                            <option value="veröffentlicht">✅ Beitrag veröffentlicht</option>
                        </select>
                    </label>
                <?php endif; ?>
            </div>
        </fieldset>
        <?php
    }
}
add_action('quick_edit_custom_box', 'stb_add_quick_edit_fields', 10, 2);

function stb_quick_edit_save_fields($post_id) {
    if (get_post_type($post_id) !== 'support_ticket') return;

    // Erledigt speichern
    if (isset($_POST['ticket_done'])) {
        update_post_meta($post_id, 'ticket_done', '1');
    } else {
        delete_post_meta($post_id, 'ticket_done');
    }

    // Blog-Status speichern
    if (isset($_POST['ticket_blog_note'])) {
        $note = sanitize_text_field($_POST['ticket_blog_note']);
        update_post_meta($post_id, 'ticket_blog_note', $note);
    }
}
add_action('save_post', 'stb_quick_edit_save_fields');

add_action('wp_head', function() {
    echo '<style>
        .ticket-form label, 
        .ticket-form input, 
        .ticket-form textarea, 
        .ticket-form select {
            text-transform: none !important;
        }
    </style>';
});

add_action('wp_head', function() {
    echo '<style>
        .ticket-form {
            max-width: 600px;
            margin: 2em auto;
            padding: 2em;
            background-color: #f9f9f9;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }

        .ticket-form p {
            margin-bottom: 1.5em;
        }

        .ticket-form label {
            font-weight: 500;
            display: block;
            margin-bottom: 0.3em;
            color: #333;
        }

        .ticket-form input[type="text"],
        .ticket-form input[type="email"],
        .ticket-form textarea,
        .ticket-form select {
            width: 100%;
            padding: 0.6em 0.8em;
            border: 1px solid #ccc;
            border-radius: 6px;
            background: #fff;
            font-size: 1em;
            box-sizing: border-box;
            transition: border-color 0.2s;
        }

        .ticket-form input:focus,
        .ticket-form textarea:focus,
        .ticket-form select:focus {
            border-color: #0073aa;
            outline: none;
        }

        .ticket-form button {
            padding: 0.6em 1.2em;
            background-color: #0073aa;
            color: white;
            font-weight: bold;
            font-size: 1em;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .ticket-form button:hover {
            background-color: #005a87;
        }

        .ticket-form input[type="checkbox"] {
            margin-right: 0.5em;
        }

        .ticket-form a {
            color: #0073aa;
            text-decoration: underline;
        }
    </style>';
});

add_action('wp_head', function() {
    echo '<style>
        .support-ticket-liste {
            max-width: 800px;
            margin: 3em auto;
            padding: 0;
            list-style: none;
            background-color: #fff;
            border: 1px solid #e2e2e2;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }

        .support-ticket-liste li {
            padding: 1em 1.5em;
            border-bottom: 1px solid #eee;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 1em;
            color: #333;
        }

        .support-ticket-liste li:last-child {
            border-bottom: none;
        }

        .support-ticket-liste li.erledigt {
            color: #999;
            text-decoration: line-through;
        }

        .support-ticket-liste li .status {
            font-size: 0.9em;
            font-weight: 500;
            color: #0073aa;
            white-space: nowrap;
            margin-left: 1em;
        }

        @media (max-width: 600px) {
            .support-ticket-liste li {
                flex-direction: column;
                align-items: flex-start;
            }

            .support-ticket-liste li .status {
                margin: 0.5em 0 0;
            }
        }
    </style>';
});

