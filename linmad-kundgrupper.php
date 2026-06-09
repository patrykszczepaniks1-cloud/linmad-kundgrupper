<?php
/**
 * Plugin Name: Linmad Kundgrupper
 * Plugin URI:  https://linmadgross.se
 * GitHub Plugin URI: patrykszczepaniks1-cloud/linmad-kundgrupper
 * Description: Hanterar kundgrupper (Bas, Silver, Guld, VIP) med fasta priser per produkt och kundgrupp för B2B-kunder i WooCommerce. Bas-kunder ser alltid standardpriset.
 * Version:     1.0.0
 * Author:      Patryk Szczepanik
 * License:     GPL-2.0+
 * Text Domain: linmad-kundgrupper
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ─────────────────────────────────────────────
// 1. KUNDGRUPPER – KONFIGURATION
// ─────────────────────────────────────────────

/**
 * Alla kundgrupper. 'bas' är alltid standardpris — lägg aldrig
 * till 'bas' i lg_get_priced_grupper().
 *
 * @return array  slug => label
 */
function lg_get_kundgrupper(): array {
    return [
        'bas'    => 'Bas',
        'silver' => 'Silver',
        'guld'   => 'Guld',
        'vip'    => 'VIP',
    ];
}

/**
 * Kundgrupper som har egna prisfält på produkten.
 * Bas exkluderas — de ser alltid WooCommerce standardpris.
 *
 * @return array  slug => label
 */
function lg_get_priced_grupper(): array {
    $all = lg_get_kundgrupper();
    unset( $all['bas'] );
    return $all;
}

define( 'LG_USER_META_KEY',    'lg_kundgrupp' );
define( 'LG_PRICE_META_PREFIX', 'lg_pris_' );


// ─────────────────────────────────────────────
// 2. PRODUKTREDIGERING – PRISFÄLT PER KUNDGRUPP
// ─────────────────────────────────────────────

add_action( 'woocommerce_product_options_pricing', 'lg_render_product_price_fields' );

function lg_render_product_price_fields(): void {
    global $post;

    echo '<div class="options_group lg-kundgrupp-priser">';
    echo '<p class="form-field"><strong style="padding-left:12px;">'
        . esc_html__( 'Priser per kundgrupp', 'linmad-kundgrupper' )
        . '</strong></p>';
    echo '<p class="form-field" style="padding-left:12px; color:#888; font-size:.9em; margin-top:-8px;">'
        . esc_html__( 'Bas-kunder ser alltid standardpriset ovan. Lämna ett fält tomt för att falla tillbaka på standardpriset.', 'linmad-kundgrupper' )
        . '</p>';

    foreach ( lg_get_priced_grupper() as $slug => $label ) {
        $meta_key = LG_PRICE_META_PREFIX . $slug;
        $value    = get_post_meta( $post->ID, $meta_key, true );

        woocommerce_wp_text_input( [
            'id'          => $meta_key,
            'name'        => $meta_key,
            'label'       => $label . ' (' . get_woocommerce_currency_symbol() . ')',
            'placeholder' => __( 'Tomt = standardpris', 'linmad-kundgrupper' ),
            'value'       => $value,
            'data_type'   => 'price',
            'desc_tip'    => true,
            'description' => sprintf(
                __( 'Fast pris för kunder i gruppen %s. Lämna tomt om standardpriset ska gälla.', 'linmad-kundgrupper' ),
                $label
            ),
        ] );
    }

    echo '</div>';
}

add_action( 'woocommerce_process_product_meta', 'lg_save_product_price_fields' );

function lg_save_product_price_fields( int $post_id ): void {
    foreach ( lg_get_priced_grupper() as $slug => $label ) {
        $meta_key = LG_PRICE_META_PREFIX . $slug;

        if ( isset( $_POST[ $meta_key ] ) && '' !== $_POST[ $meta_key ] ) {
            $price = wc_format_decimal( sanitize_text_field( wp_unslash( $_POST[ $meta_key ] ) ) );
            update_post_meta( $post_id, $meta_key, $price );
        } else {
            delete_post_meta( $post_id, $meta_key );
        }
    }
}


// ─────────────────────────────────────────────
// 3. PRISSÄTTNING – VISA RÄTT PRIS I BUTIKEN
// ─────────────────────────────────────────────

add_filter( 'woocommerce_product_get_price',                    'lg_get_kundgrupp_price', 20, 2 );
add_filter( 'woocommerce_product_get_regular_price',            'lg_get_kundgrupp_price', 20, 2 );
add_filter( 'woocommerce_product_variation_get_price',          'lg_get_kundgrupp_price', 20, 2 );
add_filter( 'woocommerce_product_variation_get_regular_price',  'lg_get_kundgrupp_price', 20, 2 );
add_filter( 'woocommerce_variation_prices_price',               'lg_get_kundgrupp_price', 20, 2 );
add_filter( 'woocommerce_variation_prices_regular_price',       'lg_get_kundgrupp_price', 20, 2 );

function lg_get_kundgrupp_price( $price, $product ) {
    if ( is_admin() && ! wp_doing_ajax() ) {
        return $price;
    }

    if ( ! is_user_logged_in() ) {
        return $price;
    }

    $grupp = lg_get_current_user_grupp();

    // Bas eller ingen grupp = standardpris.
    if ( ! $grupp || 'bas' === $grupp ) {
        return $price;
    }

    $meta_key    = LG_PRICE_META_PREFIX . $grupp;
    $grupp_price = get_post_meta( $product->get_id(), $meta_key, true );

    if ( '' !== $grupp_price && is_numeric( $grupp_price ) ) {
        return $grupp_price;
    }

    return $price;
}

add_action( 'save_post_product', 'lg_clear_variation_price_cache' );

function lg_clear_variation_price_cache( int $post_id ): void {
    $product = wc_get_product( $post_id );
    if ( $product && $product->is_type( 'variable' ) ) {
        WC_Cache_Helper::get_transient_version( 'product', true );
    }
}


// ─────────────────────────────────────────────
// 4. HJÄLPFUNKTIONER
// ─────────────────────────────────────────────

function lg_get_current_user_grupp(): string {
    if ( ! is_user_logged_in() ) {
        return '';
    }

    $grupp   = get_user_meta( get_current_user_id(), LG_USER_META_KEY, true );
    $grupper = lg_get_kundgrupper();

    return ( $grupp && isset( $grupper[ $grupp ] ) ) ? $grupp : '';
}

function lg_get_user_grupp_label( int $user_id ): string {
    $grupp   = get_user_meta( $user_id, LG_USER_META_KEY, true );
    $grupper = lg_get_kundgrupper();

    return ( $grupp && isset( $grupper[ $grupp ] ) )
        ? esc_html( $grupper[ $grupp ] )
        : '–';
}


// ─────────────────────────────────────────────
// 5. ADMIN – KUNDGRUPP PÅ ANVÄNDARPROFILEN
// ─────────────────────────────────────────────

add_action( 'show_user_profile',        'lg_render_kundgrupp_field' );
add_action( 'edit_user_profile',        'lg_render_kundgrupp_field' );
add_action( 'personal_options_update',  'lg_save_kundgrupp_field' );
add_action( 'edit_user_profile_update', 'lg_save_kundgrupp_field' );

function lg_render_kundgrupp_field( WP_User $user ): void {
    $current = get_user_meta( $user->ID, LG_USER_META_KEY, true );
    $grupper = lg_get_kundgrupper();
    ?>
    <h3><?php esc_html_e( 'Linmad Kundgrupp', 'linmad-kundgrupper' ); ?></h3>
    <table class="form-table" role="presentation">
        <tr>
            <th>
                <label for="<?php echo esc_attr( LG_USER_META_KEY ); ?>">
                    <?php esc_html_e( 'Kundgrupp', 'linmad-kundgrupper' ); ?>
                </label>
            </th>
            <td>
                <select name="<?php echo esc_attr( LG_USER_META_KEY ); ?>"
                        id="<?php echo esc_attr( LG_USER_META_KEY ); ?>">
                    <option value=""><?php esc_html_e( '– Ingen grupp –', 'linmad-kundgrupper' ); ?></option>
                    <?php foreach ( $grupper as $slug => $label ) : ?>
                        <option value="<?php echo esc_attr( $slug ); ?>"
                            <?php selected( $current, $slug ); ?>>
                            <?php echo esc_html( $label ); ?>
                            <?php echo 'bas' === $slug ? esc_html__( '(standardpris)', 'linmad-kundgrupper' ) : ''; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="description">
                    <?php esc_html_e( 'Bas-kunder ser alltid WooCommerce standardpris. Silver/Guld/VIP ser de priser som är satta per produkt.', 'linmad-kundgrupper' ); ?>
                </p>
            </td>
        </tr>
    </table>
    <?php
}

function lg_save_kundgrupp_field( int $user_id ): void {
    if ( ! current_user_can( 'edit_user', $user_id ) ) {
        return;
    }

    $grupper = lg_get_kundgrupper();
    $value   = isset( $_POST[ LG_USER_META_KEY ] ) ? sanitize_key( $_POST[ LG_USER_META_KEY ] ) : '';

    if ( $value !== '' && ! isset( $grupper[ $value ] ) ) {
        return;
    }

    update_user_meta( $user_id, LG_USER_META_KEY, $value );
}


// ─────────────────────────────────────────────
// 6. ADMIN – KOLUMN & BULK-ACTIONS I ANVÄNDARLISTAN
// ─────────────────────────────────────────────

add_filter( 'manage_users_columns',       'lg_add_user_list_column' );
add_filter( 'manage_users_custom_column', 'lg_render_user_list_column', 10, 3 );
add_filter( 'bulk_actions-users',         'lg_add_bulk_actions' );
add_filter( 'handle_bulk_actions-users',  'lg_handle_bulk_actions', 10, 3 );
add_action( 'admin_notices',              'lg_bulk_action_notice' );

function lg_add_user_list_column( array $columns ): array {
    $columns['lg_kundgrupp'] = __( 'Kundgrupp', 'linmad-kundgrupper' );
    return $columns;
}

function lg_render_user_list_column( string $output, string $column_name, int $user_id ): string {
    return 'lg_kundgrupp' === $column_name ? lg_get_user_grupp_label( $user_id ) : $output;
}

function lg_add_bulk_actions( array $actions ): array {
    foreach ( lg_get_kundgrupper() as $slug => $label ) {
        $actions[ 'lg_set_' . $slug ] = sprintf( __( 'Sätt kundgrupp: %s', 'linmad-kundgrupper' ), $label );
    }
    $actions['lg_clear_grupp'] = __( 'Ta bort kundgrupp', 'linmad-kundgrupper' );
    return $actions;
}

function lg_handle_bulk_actions( string $redirect_to, string $action, array $user_ids ): string {
    foreach ( lg_get_kundgrupper() as $slug => $label ) {
        if ( 'lg_set_' . $slug === $action ) {
            foreach ( $user_ids as $uid ) {
                update_user_meta( (int) $uid, LG_USER_META_KEY, $slug );
            }
            return add_query_arg( [ 'lg_updated' => count( $user_ids ), 'lg_grupp' => $slug ], $redirect_to );
        }
    }

    if ( 'lg_clear_grupp' === $action ) {
        foreach ( $user_ids as $uid ) {
            delete_user_meta( (int) $uid, LG_USER_META_KEY );
        }
        return add_query_arg( [ 'lg_updated' => count( $user_ids ), 'lg_grupp' => 'removed' ], $redirect_to );
    }

    return $redirect_to;
}

function lg_bulk_action_notice(): void {
    if ( empty( $_REQUEST['lg_updated'] ) ) {
        return;
    }

    $count   = (int) $_REQUEST['lg_updated'];
    $grupp   = sanitize_key( $_REQUEST['lg_grupp'] ?? '' );
    $grupper = lg_get_kundgrupper();

    if ( 'removed' === $grupp ) {
        $msg = sprintf(
            _n( 'Kundgrupp borttagen för %d användare.', 'Kundgrupp borttagen för %d användare.', $count, 'linmad-kundgrupper' ),
            $count
        );
    } else {
        $label = isset( $grupper[ $grupp ] ) ? $grupper[ $grupp ] : $grupp;
        $msg   = sprintf(
            _n( '%d användare uppdaterad till %s.', '%d användare uppdaterade till %s.', $count, 'linmad-kundgrupper' ),
            $count,
            '<strong>' . esc_html( $label ) . '</strong>'
        );
    }

    echo '<div class="notice notice-success is-dismissible"><p>' . wp_kses_post( $msg ) . '</p></div>';
}


// ─────────────────────────────────────────────
// 7. KUNDVY – VISA KUNDGRUPP PÅ MITT KONTO
// ─────────────────────────────────────────────

add_action( 'woocommerce_after_account_navigation', 'lg_display_grupp_in_my_account' );

function lg_display_grupp_in_my_account(): void {
    if ( ! is_user_logged_in() ) {
        return;
    }

    $grupp   = lg_get_current_user_grupp();
    $grupper = lg_get_kundgrupper();

    if ( ! $grupp ) {
        return;
    }
    ?>
    <div class="lg-kundgrupp-badge" style="margin-top:1rem; padding:.75rem 1rem;
         background:#f9f9f9; border-left:4px solid #2271b1; font-size:.9em;">
        <strong><?php esc_html_e( 'Din kundgrupp:', 'linmad-kundgrupper' ); ?></strong>
        <?php echo esc_html( $grupper[ $grupp ] ); ?>
    </div>
    <?php
}


// ─────────────────────────────────────────────
// 8. ORDER – SPARA KUNDGRUPP SOM ORDERMETADATA
// ─────────────────────────────────────────────

add_action( 'woocommerce_checkout_create_order',                   'lg_save_grupp_to_order', 10, 2 );
add_action( 'woocommerce_admin_order_data_after_billing_address',  'lg_show_grupp_in_order_admin' );

function lg_save_grupp_to_order( WC_Order $order, array $data ): void {
    $grupp = lg_get_current_user_grupp();
    if ( $grupp ) {
        $order->update_meta_data( '_lg_kundgrupp', $grupp );
    }
}

function lg_show_grupp_in_order_admin( WC_Order $order ): void {
    $grupp   = $order->get_meta( '_lg_kundgrupp' );
    $grupper = lg_get_kundgrupper();

    if ( ! $grupp || ! isset( $grupper[ $grupp ] ) ) {
        return;
    }
    ?>
    <p>
        <strong><?php esc_html_e( 'Kundgrupp:', 'linmad-kundgrupper' ); ?></strong>
        <?php echo esc_html( $grupper[ $grupp ] ); ?>
    </p>
    <?php
}
