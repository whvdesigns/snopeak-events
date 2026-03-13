<?php
/**
 * Organiser List Filter
 *
 * Provides client-side filtering for the organisers grid page.
 *
 * Shortcodes:
 *   [spk_org_list_filter]    — outputs search + letter pills + upcoming toggle UI
 *   [spk_org_upcoming_marker] — place inside each Bricks card loop; outputs a hidden
 *                               marker used by JS to identify cards with upcoming events
 *
 * JS filters .card-orgslist__wrapper elements against all three filters simultaneously.
 * No page reload, no AJAX.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SPK_Org_List_Filter {

    public function __construct() {
        add_shortcode( 'spk_org_list_filter',     [ $this, 'render_filter' ] );
        add_shortcode( 'spk_org_upcoming_marker', [ $this, 'render_marker' ] );
        add_action( 'wp_enqueue_scripts',         [ $this, 'enqueue' ] );
    }

    // -------------------------------------------------------------------------
    // Cache helper — one DB query, cached for 1 hour
    // -------------------------------------------------------------------------

    private function get_orgs_with_upcoming() {
        $cache_key = 'spk_orgs_with_upcoming';
        $cached    = get_transient( $cache_key );

        if ( $cached !== false ) {
            return $cached;
        }

        $today = (int) gmdate( 'Ymd', strtotime( current_time( 'Y-m-d' ) ) );

        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT pm1.meta_value
             FROM {$wpdb->postmeta} pm1
             INNER JOIN {$wpdb->postmeta} pm2 ON pm1.post_id = pm2.post_id
             INNER JOIN {$wpdb->posts} p ON pm1.post_id = p.ID
             WHERE pm1.meta_key = 'event_organiser'
             AND   pm2.meta_key = 'event_end_sort'
             AND   CAST( pm2.meta_value AS UNSIGNED ) >= %d
             AND   p.post_status = 'publish'
             AND   p.post_type   = 'event'",
            $today
        ) );

        $org_ids = [];

        foreach ( $rows as $row ) {
            $ids = maybe_unserialize( $row->meta_value );
            if ( is_array( $ids ) ) {
                foreach ( $ids as $id ) {
                    $org_ids[ (int) $id ] = true;
                }
            }
        }

        $org_ids = array_keys( $org_ids );

        set_transient( $cache_key, $org_ids, HOUR_IN_SECONDS );

        return $org_ids;
    }

    // -------------------------------------------------------------------------
    // [spk_org_upcoming_marker] — place inside each Bricks card
    // -------------------------------------------------------------------------

    public function render_marker() {
        $post_id = get_the_ID();

        if ( ! $post_id ) {
            return '';
        }

        $orgs_with_upcoming = $this->get_orgs_with_upcoming();
        $has_upcoming       = in_array( $post_id, $orgs_with_upcoming, true ) ? '1' : '0';

        return '<span class="spk-upcoming-marker" data-has-upcoming="' . esc_attr( $has_upcoming ) . '" aria-hidden="true"></span>';
    }

    // -------------------------------------------------------------------------
    // [spk_org_list_filter] — filter UI
    // -------------------------------------------------------------------------

    public function render_filter() {
        $letters = range( 'A', 'Z' );

        ob_start();
        ?>
        <div class="spk-org-filter" id="spk-org-filter" role="search" aria-label="Filter organisers">

            <div class="spk-org-filter__search">
                <input
                    type="text"
                    id="spk-org-search"
                    class="spk-org-filter__input"
                    placeholder="Search organisers&hellip;"
                    autocomplete="off"
                    aria-label="Search organisers"
                >
            </div>

            <div class="spk-org-filter__letters" role="group" aria-label="Filter by letter">
                <button class="spk-org-filter__letter is-active" data-letter="all" aria-pressed="true">All</button>
                <?php foreach ( $letters as $letter ) : ?>
                    <button class="spk-org-filter__letter" data-letter="<?php echo esc_attr( $letter ); ?>" aria-pressed="false">
                        <?php echo esc_html( $letter ); ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <div class="spk-org-filter__upcoming">
                <label class="spk-org-filter__upcoming-label">
                    <input type="checkbox" id="spk-upcoming-only" class="spk-org-filter__checkbox">
                    Upcoming events only
                </label>
            </div>

        </div>

        <p class="spk-org-filter__no-results" id="spk-org-no-results" aria-live="polite" hidden>
            No organisers found.
        </p>
        <?php
        return ob_get_clean();
    }

    // -------------------------------------------------------------------------
    // Assets
    // -------------------------------------------------------------------------

    public function enqueue() {
        if ( ! is_post_type_archive( 'event-organiser' ) ) {
            return;
        }

        wp_register_script( 'spk-org-filter', false, [], false, true );
        wp_enqueue_script( 'spk-org-filter' );
        wp_add_inline_script( 'spk-org-filter', $this->get_js() );
    }


    private function get_js() {
        return '
        (function () {
            document.addEventListener("DOMContentLoaded", function () {

                var filter       = document.getElementById("spk-org-filter");
                if (!filter) return;

                var searchInput  = document.getElementById("spk-org-search");
                var letterBtns   = document.querySelectorAll(".spk-org-filter__letter");
                var upcomingOnly = document.getElementById("spk-upcoming-only");
                var noResults    = document.getElementById("spk-org-no-results");
                var cards        = document.querySelectorAll(".card-orgslist__wrapper");

                var activeLetter = "all";

                function getCardName(card) {
                    return card.textContent.trim().toLowerCase();
                }

                function getCardFirstLetter(card) {
                    return getCardName(card).charAt(0).toUpperCase();
                }

                function cardHasUpcoming(card) {
                    var marker = card.querySelector(".spk-upcoming-marker");
                    return marker && marker.dataset.hasUpcoming === "1";
                }

                function applyFilters() {
                    var search   = searchInput.value.trim().toLowerCase();
                    var upcoming = upcomingOnly.checked;
                    var visible  = 0;

                    // Collect which letters have visible cards under current search+upcoming filters
                    var activeLetters = {};
                    cards.forEach(function (card) {
                        var name          = getCardName(card);
                        var letter        = getCardFirstLetter(card);
                        var matchSearch   = !search || name.indexOf(search) !== -1;
                        var matchUpcoming = !upcoming || cardHasUpcoming(card);
                        if (matchSearch && matchUpcoming) {
                            activeLetters[letter] = true;
                        }
                    });

                    cards.forEach(function (card) {
                        var name          = getCardName(card);
                        var letter        = getCardFirstLetter(card);
                        var matchSearch   = !search || name.indexOf(search) !== -1;
                        var matchLetter   = activeLetter === "all" || letter === activeLetter;
                        var matchUpcoming = !upcoming || cardHasUpcoming(card);

                        var show = matchSearch && matchLetter && matchUpcoming;
                        card.dataset.hidden = show ? "false" : "true";
                        if (show) visible++;
                    });

                    // Mark letters disabled if no cards match current search+upcoming
                    letterBtns.forEach(function (btn) {
                        var l = btn.dataset.letter;
                        if (l === "all") return;
                        var hasCards = !!activeLetters[l];
                        btn.classList.toggle("is-disabled", !hasCards);
                    });

                    noResults.hidden = visible > 0;
                }

                // Letter buttons
                letterBtns.forEach(function (btn) {
                    btn.addEventListener("click", function () {
                        activeLetter = btn.dataset.letter;
                        letterBtns.forEach(function (b) {
                            b.classList.toggle("is-active", b === btn);
                            b.setAttribute("aria-pressed", b === btn ? "true" : "false");
                        });
                        applyFilters();
                    });
                });

                // Search
                searchInput.addEventListener("input", applyFilters);

                // Upcoming toggle
                upcomingOnly.addEventListener("change", applyFilters);

                // Set initial letter disabled state on page load
                applyFilters();
            });
        })();
        ';
    }
}

new SPK_Org_List_Filter();
