<?php
/**
 * GenerateBlocks Style Transfer — Admin Page Snippet
 *
 * Paste into WPCodeBox as a PHP snippet. Run everywhere (admin only).
 * Adds a "Style Transfer" page between Global Styles and Overlay Panels
 * in the GenerateBlocks admin menu.
 *
 * Functions:
 *   - Export: Download all gblocks_styles as JSON
 *   - Import: Paste JSON → Preview → Confirm (wipe + replace)
 *   - Status: Shows current Global Style count
 *
 * @package gb-converter
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ── Menu Registration ───────────────────────────────────

add_action( 'admin_menu', 'gb_st_register_page', 9 );

function gb_st_register_page() {
    add_submenu_page(
        'generateblocks',
        __( 'Style Transfer', 'generateblocks-pro' ),
        __( 'Style Transfer', 'generateblocks-pro' ),
        'manage_options',
        'generateblocks-style-transfer',
        'gb_st_render_page',
        4
    );
}

// ── Page Render ─────────────────────────────────────────

function gb_st_render_page() {
    $styles_count = gb_st_get_count();
    $message = '';
    $message_type = 'success';

    // Handle export
    if ( isset( $_GET['action'] ) && 'export' === $_GET['action'] ) {
        gb_st_handle_export();
        return;
    }

    // Handle preview
    if ( isset( $_POST['gb_st_preview'] ) && check_admin_referer( 'gb_st_import' ) ) {
        $json = stripslashes( $_POST['gb_st_json'] ?? '' );

        if ( empty( trim( $json ) ) ) {
            $message = 'Please paste JSON content.';
            $message_type = 'error';
        } else {
            $validation = gb_st_validate( $json );
            if ( ! $validation['valid'] ) {
                $message = implode( '<br>', array_map( 'esc_html', $validation['errors'] ) );
                $message_type = 'error';
            } else {
                // Store in transient for commit step
                set_transient( 'gb_st_pending_import', $json, 15 * MINUTE_IN_SECONDS );
                $preview_count = count( $validation['entries'] );
                $message = sprintf(
                    '%d styles found. 0 errors. This will DELETE all %d existing styles and import %d new ones.',
                    $preview_count,
                    $styles_count,
                    $preview_count
                );
                $message_type = 'preview';
            }
        }
    }

    // Handle commit
    if ( isset( $_POST['gb_st_commit'] ) && check_admin_referer( 'gb_st_import' ) ) {
        $json = get_transient( 'gb_st_pending_import' );
        if ( ! $json ) {
            $message = 'No pending import found. Please paste and preview again.';
            $message_type = 'error';
        } else {
            $result = gb_st_commit( $json );
            delete_transient( 'gb_st_pending_import' );
            if ( $result['success'] ) {
                $message = sprintf( 'Import complete. %d styles imported.', $result['count'] );
                $message_type = 'success';
                $styles_count = $result['count'];
            } else {
                $message = 'Import failed: ' . esc_html( $result['error'] );
                $message_type = 'error';
            }
        }
    }

    $show_preview = ( 'preview' === $message_type );
    ?>
    <div class="wrap gblocks-dashboard-wrap">
        <div class="gblocks-dashboard-header">
            <div class="gblocks-dashboard-header-title">
                <h1><?php esc_html_e( 'Style Transfer', 'generateblocks-pro' ); ?></h1>
            </div>
        </div>

        <div class="generateblocks-settings-area" style="max-width:800px;margin-top:20px;">
            <?php if ( $message ) : ?>
                <div class="notice notice-<?php echo $show_preview ? 'warning' : esc_attr( $message_type ); ?> inline">
                    <p><?php echo $message; // Already escaped or from trusted source ?></p>
                </div>
            <?php endif; ?>

            <?php if ( ! $show_preview ) : ?>
                <?php // Status ?>
                <div class="gb-st-status" style="background:#f0f6fc;padding:15px;margin-bottom:20px;border-radius:4px;">
                    <strong>Status:</strong> <?php echo (int) $styles_count; ?> Global Styles currently registered.
                </div>

                <?php // Export ?>
                <div class="gb-st-section" style="background:#fff;border:1px solid #ccd0d4;padding:20px;margin-bottom:20px;border-radius:4px;">
                    <h2 style="margin-top:0;">Export</h2>
                    <p>Download all Global Styles as a JSON file.</p>
                    <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=generateblocks-style-transfer&action=export' ), 'gb_st_export' ) ); ?>" class="button button-primary">
                        Download global-styles.json
                    </a>
                </div>

                <?php // Import ?>
                <div class="gb-st-section" style="background:#fff;border:1px solid #ccd0d4;padding:20px;border-radius:4px;">
                    <h2 style="margin-top:0;">Import</h2>
                    <p>Paste the contents of a <code>global-styles.json</code> file. <strong>All existing styles will be replaced.</strong></p>
                    <form method="post">
                        <?php wp_nonce_field( 'gb_st_import' ); ?>
                        <textarea
                            name="gb_st_json"
                            rows="15"
                            style="width:100%;font-family:monospace;font-size:13px;"
                            placeholder='[{"selector":".my-class","css":".my-class{color:red}","data":{}}]'
                        ></textarea>
                        <p style="margin-top:10px;">
                            <button type="submit" name="gb_st_preview" class="button button-primary">Paste &amp; Preview</button>
                        </p>
                    </form>
                </div>

            <?php else : ?>
                <?php // Commit confirmation ?>
                <div class="gb-st-section" style="background:#fff;border:2px solid #f0b849;padding:20px;border-radius:4px;">
                    <h2 style="margin-top:0;">Confirm Import</h2>
                    <p><?php echo $message; // Already escaped ?></p>
                    <form method="post" style="display:inline;">
                        <?php wp_nonce_field( 'gb_st_import' ); ?>
                        <button type="submit" name="gb_st_commit" class="button button-primary">Confirm Import</button>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=generateblocks-style-transfer' ) ); ?>" class="button">Cancel</a>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

// ── Export Handler ───────────────────────────────────────

function gb_st_handle_export() {
    check_admin_referer( 'gb_st_export' );

    header( 'Content-Type: application/json' );
    header( 'Content-Disposition: attachment; filename="global-styles.json"' );

    // Begin JSON array output
    echo '[';

    $paged          = 1;
    $posts_per_page = 50;
    $first_item     = true;

    while ( true ) {
        $posts = get_posts( [
            'post_type'      => 'gblocks_styles',
            'post_status'    => 'publish',
            'posts_per_page' => $posts_per_page,
            'orderby'        => 'menu_order',
            'order'          => 'ASC',
            'paged'          => $paged,
        ] );

        if ( empty( $posts ) ) {
            break;
        }

        foreach ( $posts as $post ) {
            if ( ! $first_item ) {
                echo ',';
            } else {
                $first_item = false;
            }

            $entry = [
                'selector' => get_post_meta( $post->ID, 'gb_style_selector', true ) ?: '',
                'css'      => get_post_meta( $post->ID, 'gb_style_css', true ) ?: '',
                'data'     => gb_st_unserialize_data( get_post_meta( $post->ID, 'gb_style_data', true ) ),
            ];

            echo json_encode( $entry, JSON_UNESCAPED_SLASHES );
        }

        $paged++;
        if ( function_exists( 'ob_flush' ) ) {
            ob_flush();
        }
        flush();
    }

    echo ']';
    exit;
}

// ── Validation ───────────────────────────────────────────

function gb_st_validate( $json ) {
    $errors = [];

    $data = json_decode( $json, true );
    if ( json_last_error() !== JSON_ERROR_NONE ) {
        return [
            'valid'  => false,
            'errors' => [ 'Invalid JSON: ' . json_last_error_msg() ],
        ];
    }

    if ( ! is_array( $data ) ) {
        return [
            'valid'  => false,
            'errors' => [ 'Root must be a JSON array.' ],
        ];
    }

    if ( strlen( $json ) > 500000 ) {
        return [
            'valid'  => false,
            'errors' => [ 'Content too large. Max 500KB.' ],
        ];
    }

    $selectors_seen = [];
    $valid_entries = [];

    foreach ( $data as $i => $entry ) {
        $n = $i + 1;

        if ( ! isset( $entry['selector'] ) || ! is_string( $entry['selector'] ) ) {
            $errors[] = "Entry #{$n}: missing required field 'selector'";
            continue;
        }
        if ( substr( $entry['selector'], 0, 1 ) !== '.' ) {
            $errors[] = "Entry #{$n}: selector must start with '.'";
            continue;
        }
        if ( ! isset( $entry['css'] ) || ! is_string( $entry['css'] ) ) {
            $errors[] = "Entry #{$n}: missing required field 'css'";
            continue;
        }

        $sel = $entry['selector'];
        if ( isset( $selectors_seen[ $sel ] ) ) {
            $errors[] = "Entry #{$n} and #{$selectors_seen[$sel]}: duplicate selector '{$sel}'";
            continue;
        }
        $selectors_seen[ $sel ] = $n;

        if ( isset( $entry['data'] ) && ! is_array( $entry['data'] ) ) {
            $errors[] = "Entry #{$n}: 'data' must be an object if present";
            continue;
        }

        $valid_entries[] = $entry;
    }

    if ( ! empty( $errors ) ) {
        return [ 'valid' => false, 'errors' => $errors ];
    }

    return [ 'valid' => true, 'entries' => $valid_entries ];
}

// ── Commit ───────────────────────────────────────────────

function gb_st_commit( $json ) {
    $validation = gb_st_validate( $json );
    if ( ! $validation['valid'] ) {
        return [
            'success' => false,
            'error'   => implode( '; ', $validation['errors'] ),
        ];
    }

    // Delete existing styles
    $existing = get_posts( [
        'post_type'      => 'gblocks_styles',
        'post_status'    => [ 'publish', 'draft' ],
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ] );

    foreach ( $existing as $post_id ) {
        wp_delete_post( $post_id, true );
    }

    // Insert new styles
    $inserted = 0;
    foreach ( $validation['entries'] as $i => $entry ) {
        $post_id = wp_insert_post( [
            'post_type'   => 'gblocks_styles',
            'post_title'  => sanitize_text_field( $entry['selector'] ),
            'post_status' => 'publish',
            'menu_order'  => $i,
        ] );

        if ( is_wp_error( $post_id ) ) {
            // Roll back — delete any we've inserted
            $inserted_posts = get_posts( [
                'post_type'      => 'gblocks_styles',
                'post_status'    => 'publish',
                'posts_per_page' => $inserted,
                'fields'         => 'ids',
                'orderby'        => 'ID',
                'order'          => 'DESC',
            ] );
            foreach ( $inserted_posts as $pid ) {
                wp_delete_post( $pid, true );
            }
            return [
                'success' => false,
                'error'   => 'Failed to insert style "' . esc_html( $entry['selector'] ) . '": ' . $post_id->get_error_message(),
            ];
        }

        update_post_meta( $post_id, 'gb_style_selector', sanitize_text_field( $entry['selector'] ) );
        update_post_meta( $post_id, 'gb_style_css', wp_kses_post( $entry['css'] ) );

        if ( ! empty( $entry['data'] ) && is_array( $entry['data'] ) ) {
            update_post_meta( $post_id, 'gb_style_data', $entry['data'] );
        }

        $inserted++;
    }

    // Clear cached CSS
    delete_option( 'generateblocks_style_css' );

    return [ 'success' => true, 'count' => $inserted ];
}

// ── Helpers ──────────────────────────────────────────────

function gb_st_get_count() {
    $counts = wp_count_posts( 'gblocks_styles' );
    return isset( $counts->publish ) ? (int) $counts->publish : 0;
}

function gb_st_unserialize_data( $data ) {
    if ( empty( $data ) ) {
        return [];
    }

    // Try unserializing — if it's a serialized PHP string
    if ( is_string( $data ) ) {
        $unserialized = @unserialize( $data );
        if ( is_array( $unserialized ) ) {
            return $unserialized;
        }
    }

    // If it's already an array (GB Pro stores as serialized but WP might auto-unserialize)
    if ( is_array( $data ) ) {
        return $data;
    }

    return [];
}
