<?php
/**
 * Register all actions and filters for the plugin.
 *
 * @package CubePaymentPortal
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class SPP_Loader
 *
 * Maintains a list of all hooks and registers them with WordPress.
 */
class SPP_Loader {

    /**
     * The array of actions registered with WordPress.
     *
     * @var array
     */
    protected $actions;

    /**
     * The array of filters registered with WordPress.
     *
     * @var array
     */
    protected $filters;

    /**
     * Initialize the collections.
     */
    public function __construct() {
        $this->actions = array();
        $this->filters = array();
    }

    /**
     * Add a new action to the collection.
     *
     * @param string $hook          The WordPress hook name.
     * @param object $component     The object containing the callback.
     * @param string $callback      The callback method name.
     * @param int    $priority      The priority of the action.
     * @param int    $accepted_args Number of arguments the callback accepts.
     */
    public function add_action( $hook, $component, $callback, $priority = 10, $accepted_args = 1 ) {
        $this->actions = $this->add( $this->actions, $hook, $component, $callback, $priority, $accepted_args );
    }

    /**
     * Add a new filter to the collection.
     *
     * @param string $hook          The WordPress hook name.
     * @param object $component     The object containing the callback.
     * @param string $callback      The callback method name.
     * @param int    $priority      The priority of the filter.
     * @param int    $accepted_args Number of arguments the callback accepts.
     */
    public function add_filter( $hook, $component, $callback, $priority = 10, $accepted_args = 1 ) {
        $this->filters = $this->add( $this->filters, $hook, $component, $callback, $priority, $accepted_args );
    }

    /**
     * Utility function to add to hook collection.
     *
     * @param array  $hooks         The current collection of hooks.
     * @param string $hook          The hook name.
     * @param object $component     The component.
     * @param string $callback      The callback.
     * @param int    $priority      The priority.
     * @param int    $accepted_args The number of args.
     * @return array The modified hooks collection.
     */
    private function add( $hooks, $hook, $component, $callback, $priority, $accepted_args ) {
        $hooks[] = array(
            'hook'          => $hook,
            'component'     => $component,
            'callback'      => $callback,
            'priority'      => $priority,
            'accepted_args' => $accepted_args,
        );

        return $hooks;
    }

    /**
     * Register all hooks with WordPress.
     */
    public function run() {
        foreach ( $this->filters as $hook ) {
            add_filter(
                $hook['hook'],
                array( $hook['component'], $hook['callback'] ),
                $hook['priority'],
                $hook['accepted_args']
            );
        }

        foreach ( $this->actions as $hook ) {
            add_action(
                $hook['hook'],
                array( $hook['component'], $hook['callback'] ),
                $hook['priority'],
                $hook['accepted_args']
            );
        }
    }
}
