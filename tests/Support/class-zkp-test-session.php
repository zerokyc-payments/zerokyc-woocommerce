<?php
/**
 * Minimal in-memory WC_Session replacement for tests: wc_add_notice() writes
 * into WC()->session, which is null under the WP test suite.
 *
 * @package zerokyc-pay
 */

if ( ! class_exists( 'ZKP_Test_Session' ) && interface_exists( 'WC_Session' ) ) {

	class ZKP_Test_Session implements WC_Session {

		/** @var array<string,mixed> */
		private array $data = array();

		public function init() {}

		public function cleanup_session() {}

		public function destroy_session() {}

		public function save_data() {}

		public function set_session_cookie() {}

		public function get_session_cookie() {
			return false;
		}

		/**
		 * @param string $key
		 * @param mixed  $default
		 * @return mixed
		 */
		#[ReturnTypeWillChange]
		public function get( $key, $default = null ) {
			return $this->data[ $key ] ?? $default;
		}

		/**
		 * @param string $key
		 * @param mixed  $value
		 */
		public function set( $key, $value ) {
			$this->data[ $key ] = $value;
		}

		/**
		 * @param string $key
		 */
		#[ReturnTypeWillChange]
		public function __unset( $key ) {
			unset( $this->data[ $key ] );
		}

		/**
		 * @param mixed $offset
		 * @return bool
		 */
		#[ReturnTypeWillChange]
		public function offsetExists( $offset ) {
			return isset( $this->data[ $offset ] );
		}

		/**
		 * @param mixed $offset
		 * @return mixed
		 */
		#[ReturnTypeWillChange]
		public function offsetGet( $offset ) {
			return $this->data[ $offset ] ?? null;
		}

		/**
		 * @param mixed $offset
		 * @param mixed $value
		 */
		#[ReturnTypeWillChange]
		public function offsetSet( $offset, $value ) {
			$this->data[ $offset ] = $value;
		}

		/**
		 * @param mixed $offset
		 */
		#[ReturnTypeWillChange]
		public function offsetUnset( $offset ) {
			unset( $this->data[ $offset ] );
		}
	}
}
