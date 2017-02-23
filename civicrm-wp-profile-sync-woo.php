<?php

/**
 * CiviCRM WooCommerce Profile Sync class.
 *
 * A class that encapsulates WooCommerce sync functionality.
 *
 * @since 0.3
 */
class CiviCRM_WP_Profile_Sync_Woo {

	/**
	 * Current buffered WordPress user ID.
	 *
	 * @since 0.3
	 * @access protected
	 * @var int $_wp_user_id The WordPress user ID.
	 */
	protected $_wp_user_id = null;

	/**
	 * Current buffered CiviCRM contact ID.
	 *
	 * @since 0.3
	 * @access protected
	 * @var int $_civi_contact_id The CiviCRM contact ID.
	 */
	protected $_civi_contact_id = null;

	/**
	 * Current buffered CiviCRM contact primary address ID and Type.
	 *
	 * @since 0.3
	 * @access protected
	 * @var array $_civi_primary_address_info The CiviCRM contact primary address ID and Type.
	 */
	protected $_civi_primary_address_info = array();

	/**
	 * Current buffered CiviCRM contact billing address ID and Type.
	 *
	 * @since 0.3
	 * @access protected
	 * @var array $_civi_billing_address_info The CiviCRM contact billing address ID and Type.
	 */
	protected $_civi_billing_address_info = array();

	/**
	 * Current buffered CiviCRM contact primary phone ID.
	 *
	 * @since 0.3
	 * @access protected
	 * @var array $_civi_primary_phone_id The CiviCRM contact primary phone ID.
	 */
	protected $_civi_primary_phone_id = null;

	/**
	 * Field names mapping using CiviCRM API.
	 *
	 * @since 0.3
	 * @access protected
	 * @var array $_address_api_mapping_wc_to_civi The field names mapping.
	 */
	protected static $_address_api_mapping_wc_to_civi = array(
		'country' => 'country_id',
		'address_1' => 'street_address',
		'address_2' => 'supplemental_address_1',
		'city' => 'city',
		'state' => 'state_province_id',
		'postcode' => 'postal_code',
	);



	/**
	 * Constructor.
	 *
	 * @since 0.3
	 *
	 * @param obj $plugin The reference to the main plugin class.
	 */
	public function __construct( $plugin ) {

		// store reference
		$this->plugin = $plugin;

		// post process a CiviCRM contact when the WordPress user is updated,
		// done late to let other plugins go first
		$this->_add_hooks_wp_wc();

		// sync a WordPress user when a CiviCRM contact is updated
		$this->_add_hooks_civi_wc();

	}



	/**
	 * Synchronise changes of address fields of user metadata in WordPress &
	 * WooCommerce to CiviCRM.
	 *
	 * @since 0.3
	 *
	 * @param int $meta_id ID of updated metadata entry.
	 * @param int $object_id Object ID.
	 * @param string $meta_key Meta key.
	 * @param mixed $meta_value Meta value.
	 */
	public function update_civi_address_fields( $meta_id, $object_id, $meta_key, $_meta_value ) {

		$_lower_case_meta_key = strtolower( $meta_key );

		if (
			$_lower_case_meta_key == 'last_update' OR
			(
				strpos( $_lower_case_meta_key, 'billing_' ) === false AND
				strpos( $_lower_case_meta_key, 'shipping_' ) === false
			)
		) {
			return;
		}

		/*
		$this->plugin->_debug( array(
			'method' => __METHOD__,
			'meta_id' => $meta_id,
			'object_id' => $object_id,
			'meta_key' => $meta_key,
			'meta_value' => $_meta_value,
		) );
		*/

		$_get_new_ids = ( isset( $this->_wp_user_id ) AND $this->_wp_user_id == $object_id ) ? false : true;

		if ( $_get_new_ids ) {

			// okay, get user object
			$user = get_userdata( $object_id );

			// did we get one?
			if ( $user ) {

				// init CiviCRM
				if ( ! civi_wp()->initialize() ) return;

				// get user matching file
				require_once 'CRM/Core/BAO/UFMatch.php';

				// get the Civi contact object
				$civi_contact = CRM_Core_BAO_UFMatch::synchronizeUFMatch(
					$user, // user object
					$user->ID, // ID
					$user->user_email, // unique identifier
					'WordPress', // CMS
					null, // status (unused)
					'Individual' // contact type
				);

				// bail if we don't get one for some reason
				if ( ! isset( $civi_contact->contact_id ) ) {
					return;
				}

				$this->_wp_user_id = $user->ID;
				$this->_civi_contact_id = $civi_contact->contact_id;

				//get primary address id and type and store them within the object.
				$result = civicrm_api3( 'Address', 'get', array(
					'sequential' => 1,
					'return' => array( 'id', 'location_type_id' ),
					'contact_id' => $this->_civi_contact_id,
					'is_primary' => 1,
				) );

				if ( empty( $result['values'] ) ) {
					$this->_civi_primary_address_info = array();
				} else {
					$this->_civi_primary_address_info = array(
						'id' => $result['values'][0]['id'],
						'type' => $result['values'][0]['location_type_id'],
					);
				}

				// get billing address id and type and store them within the object.
				$result = civicrm_api3( 'Address', 'get', array(
					'sequential' => 1,
					'return' => array( 'id', 'location_type_id' ),
					'contact_id' => $this->_civi_contact_id,
					'is_billing' => 1,
				) );

				if ( empty( $result['values'] ) ) {
					$this->_civi_billing_address_info = array();
				} else {
					$this->_civi_billing_address_info = array(
						'id' => $result['values'][0]['id'],
						'type' => $result['values'][0]['location_type_id'],
					);
				}

				// get primary phone id and store it within the object.
				$result = civicrm_api3( 'Phone', 'get', array(
					'sequential' => 1,
					'return' => array( 'id' ),
					'contact_id' => $this->_civi_contact_id,
					'is_primary' => 1,
				) );

				if ( empty( $result['values'] ) ) {
					$this->_civi_primary_phone_id = null;
				} else {
					$this->_civi_primary_phone_id = $result['values'][0]['id'];
				}

				/*
				$this->plugin->_debug( array(
					'_civi_primary_address_info' => $this->_civi_primary_address_info,
					'_civi_billing_address_info' => $this->_civi_billing_address_info,
				) );
				*/

			}

		}

		$this->_remove_hooks_civi_wc();

		// perform sync
		$this->_sync_to_civicrm_phone( $meta_key, $_meta_value );
		$this->_sync_to_civicrm_addresses( $meta_key, $_meta_value );

		$this->_add_hooks_civi_wc();

	}


	/**
	 * Synchronise changes of billing phone of woo user metadata in WordPress &
	 * WooCommerce to CiviCRM.
	 *
	 * @since 0.3
	 *
	 * @param string $_meta_key Meta key.
	 * @param mixed $_meta_value Meta value.
	 */
	private function _sync_to_civicrm_phone( $_meta_key, $_meta_value ) {

		if ( $_meta_key != 'billing_phone' ) {
			return;
		}

		$_need_to_update_phone_id = false;

		$_query_array = array(
			'sequential' => 1,
			'contact_id' => $this->_civi_contact_id,
		);

		$_query_array['phone'] = $_meta_value;

		if ( isset( $this->_civi_primary_phone_id ) ) {
			$_query_array['id'] = $this->_civi_primary_phone_id;
		} else {
			$_query_array['phone_type_id'] = 'Phone';
		}

		if ( ! isset( $_query_array['id'] ) ) {
			$_query_array['location_type_id'] = 'Billing';

			$_need_to_update_phone_id = true;
		}

		try {
			$result = civicrm_api3( 'Phone', 'create', $_query_array );
		} catch ( Exception $e ) {
			$this->plugin->_debug( $e->getMessage() );
		}

		if ( $_need_to_update_phone_id ) {
			$result = $result['values'][0];

			$this->_civi_primary_phone_id = $result['id'];
		}

	}


	/**
	 * Synchronise changes of billing and shipping address of woo user metadata
	 * in WordPress & WooCommerce to CiviCRM.
	 *
	 * @since 0.3
	 *
	 * @param string $_meta_key Meta key.
	 * @param mixed $_meta_value Meta value.
	 */
	private function _sync_to_civicrm_addresses( $_meta_key, $_meta_value ) {

		/*
		$this->plugin->_debug( array(
			'method' => __METHOD__,
			'meta_key' => $_meta_key,
			'meta_value' =>  $_meta_value,
		) );
		*/

		$tmp = explode( '_', $_meta_key );

		$_address_type = strtolower( array_shift( $tmp ) );
		$_processed_meta_key = implode( '_', $tmp );

		if ( array_key_exists( $_processed_meta_key, self::$_address_api_mapping_wc_to_civi ) ) {

			/*
			 * The country field is working fine, as CiviCRM API can recognise
			 * short names of countries but WooCommerce is letting users enter
			 * the state/province names by themselves on their WordPress Profile
			 * Page (in the WordPress back end) so it is likely that the state
			 * field will not be correctly updated.
			 *
			 * We need to store the state's full name mapping for a corresponding
			 * country in the object, otherwise the CiviCRM API cannot recognise
			 * the abbreviation of state names provided by WooCommerce.
			 */

			if ( strpos( strtolower( $_processed_meta_key ), 'state' ) !== false ) {

				$_country_value = get_user_meta( $this->_wp_user_id, $_address_type . '_country', true );

				$_states_list = WC()->countries->get_states( $_country_value );

				$_meta_value = $_states_list[$_meta_value];

			}

			$_query_array = array(
				'sequential' => 1,
				'contact_id' => $this->_civi_contact_id,
				self::$_address_api_mapping_wc_to_civi[$_processed_meta_key] => $_meta_value,
			);

		} else {
			return;
		}

		/*
		 * NOTE if the the address info is empty, we need to update the attribute
		 * after creating a new address in CiviCRM otherwise each call will create
		 * a new address.
		 */

		$_need_to_update_object_address_info = false;

		if ( $_address_type == 'billing' ) {

			$_query_array['is_billing'] = 1;

			if ( isset( $this->_civi_billing_address_info['id'] ) ) {
				$_need_to_update_object_address_info = false;
				$_query_array['id'] = $this->_civi_billing_address_info['id'];
				$_query_array['location_type_id'] = ( isset( $this->_civi_billing_address_info['type'] ) ) ? $this->_civi_billing_address_info['type'] : 'Billing';
			} else {
				$_need_to_update_object_address_info = true;
				$_query_array['location_type_id'] = 'Billing';
			}

		} elseif ( $_address_type == 'shipping' ) {

			$_query_array['is_primary'] = 1;

			if ( isset( $this->_civi_primary_address_info['id'] ) ) {
				$_need_to_update_object_address_info = false;
				$_query_array['id'] = $this->_civi_primary_address_info['id'];
				$_query_array['location_type_id'] = ( isset( $this->_civi_primary_address_info['type'] ) ) ? $this->_civi_primary_address_info['type'] : 'Home';
			} else {
				$_need_to_update_object_address_info = true;
				$_query_array['location_type_id'] = 'Home';
			}

		} else {
			return;
		}

		try {
			$result = civicrm_api3( 'Address', 'create', $_query_array );
		} catch ( Exception $e ) {
			$this->plugin->_debug( $e->getMessage() );
		}

		// update the buffered address info if needed.
		if ( $_need_to_update_object_address_info ) {
			$result = $result['values'][0];

			/*
			 * NOTE we need to check `is_billing` first, as first it follows the
			 * order of Woo's user metadata. Secondly if only billing address
			 * information is filled out and the user doesn't have any address
			 * in CiviCRM, CiviCRM will mark the first address created as
			 * `primary` too. This will cause incorrect data input of the first
			 * address field, if we check `is_primary` first.
			 */

			if ( $result['is_billing'] == 1 ) {

				$this->_civi_billing_address_info = array(
					'id' => $result['id'],
					'type' => $result['location_type_id'],
				);

			} elseif ( $result['is_primary'] == 1 ) {

				$this->_civi_primary_address_info = array(
					'id' => $result['id'],
					'type' => $result['location_type_id'],
				);

			} else {
				return;
			}

		}

	}


	/**
	 * Synchronise primary phone changes (edit, create, delete) in CiviCRM to
	 * WordPress & WooCommerce, hooked into pre process.
	 *
	 * @since 0.3
	 *
	 * @param string $_meta_key Meta key.
	 * @param mixed $_meta_value Meta value.
	 */
	public function civi_primary_phone_update( $op, $objectName, $objectId, $objectRef ) {

		// target our object type
		if ( $objectName != 'Phone' ) return;

		/*
		$this->plugin->_debug( array(
			'method' => __METHOD__,
			'op' => $op,
			'objectName' => $objectName,
			'objectId' => $objectId,
			'objectRef' => $objectRef,
		) );
		*/

		$_is_deletion = false;

		if ( $op == 'delete' ) {

			$result = civicrm_api3( $objectName, 'get', array(
				'sequential' => 1,
				'id' => $objectId,
			) );

			$result = $result['values'][0];

			if ( $result['is_primary'] == 1 ) {
				$objectRef['contact_id'] = $result['contact_id'];
				$_is_deletion = true;
			} else {
				return;
			}

		} elseif ( $op == 'edit' OR $op == 'create' ) {

			// bail if we have no contact ID
			if ( ! isset( $objectRef['contact_id'] ) ) return;

			// we only care about primary phone
			if ( ! isset( $objectRef['is_primary'] ) ) {
				return;
			} elseif ( $objectRef['is_primary'] == '0' ) {
				return;
			}

		}

		// init CiviCRM to get WordPress user ID
		if ( ! civi_wp()->initialize() ) return;

		// make sure Civi file is included
		require_once 'CRM/Core/BAO/UFMatch.php';

		// search using Civi's logic
		$user_id = CRM_Core_BAO_UFMatch::getUFId( $objectRef['contact_id'] );

		// kick out if we didn't get one
		if ( empty( $user_id ) ) return;

		// remove WordPress callbacks to prevent recursion
		$this->_remove_hooks_wp_wc();

		if ( $_is_deletion ) {
			$result = update_user_meta( $user_id, 'billing_phone', '' );
		} else {
			update_user_meta( $user_id, 'billing_phone', $objectRef['phone'] );
		}

		// re-add WordPress callbacks
		$this->_add_hooks_wp_wc();

	}


	/**
	 * Synchronise primary and billing addresses.
	 *
	 * Sync changes (edit, create, delete) in CiviCRM to WordPress & WooCommerce,
	 * hooked into pre process.
	 *
	 * @since 0.3
	 *
	 * @param string $op The type of database operation
	 * @param string $objectName The type of object
	 * @param integer $objectId The ID of the object
	 * @param object $objectRef The object
	 */
	public function civi_primary_n_billing_addresses_update( $op, $objectName, $objectId, $objectRef ) {

		// target our object type
		if ( $objectName != 'Address' ) return;

		/*
		$this->plugin->_debug( array(
			'method' => __METHOD__,
			'op' => $op,
			'objectName' => $objectName,
			'objectId' => $objectId,
			'objectRef' => $objectRef,
		) );
		*/

		$_is_deletion = false;

		if ( $op == 'delete' ) {

			$result = civicrm_api3( $objectName, 'get', array(
				'sequential' => 1,
				'id' => $objectId,
			) );

			$result = $result['values'][0];

			if ( $result['is_primary'] == '1' ) {
				$objectRef['is_primary'] = '1';
				$objectRef['contact_id'] = $result['contact_id'];
				$_is_deletion = true;
			}

			if ( $result['is_billing'] == '1' ) {
				$objectRef['is_billing'] = '1';
				$objectRef['contact_id'] = $result['contact_id'];
				$_is_deletion = true;
			}

			if ( ! $_is_deletion ) {
				return;
			}

		} elseif ( $op == 'edit' OR $op == 'create' ) {

			// bail if we have no contact ID
			if ( ! isset( $objectRef['contact_id'] ) ) return;

			// we only care about primary address and billing address
			if ( $objectRef['is_primary'] == '0' AND $objectRef['is_billing'] == '0' ) return;

		}

		// init CiviCRM to get WordPress user ID
		if ( ! civi_wp()->initialize() ) return;

		// make sure Civi file is included
		require_once 'CRM/Core/BAO/UFMatch.php';

		// search using Civi's logic
		$user_id = CRM_Core_BAO_UFMatch::getUFId( $objectRef['contact_id'] );

		// kick out if we didn't get one
		if ( empty( $user_id ) ) return;

		// remove WordPress WooCommerce callbacks to prevent recursion
		$this->_remove_hooks_wp_wc();

		if ( $objectRef['is_primary'] == '1' ) {
			$this->_update_address_info_civicrm( $user_id, $objectRef, 'shipping', $_is_deletion );
		}

		if ( $objectRef['is_billing'] == '1' ) {
			$this->_update_address_info_civicrm( $user_id, $objectRef, 'billing', $_is_deletion );
		}

		// re-add WordPress WooCommerce callbacks
		$this->_add_hooks_wp_wc();

	}



	/**
	 * Update corresponding types of addresses in WordPress & WooCommerce.
	 *
	 * @since 0.3
	 *
	 * @param integer $user_id The user id in WordPress
	 * @param object $objectRef The object of user in CiviCRM
	 * @param string $_address_type The location type of the address
	 * @param boolean $_is_deletion Whether this operation is deletion or not.
	 */
	private function _update_address_info_civicrm( $user_id, $objectRef, $_address_type, $_is_deletion ) {

		// look up all fields that we care about in CiviCRM object.
		foreach ( self::$_address_api_mapping_wc_to_civi as $key => $value ) {

			if ( $key == 'state' AND isset( $objectRef[$value] ) AND $objectRef[$value] != 'null' AND ! $_is_deletion ) {

				/*
				 * CiviCRM and WooCommerce both appear to use standard state and
				 * country abbreviations - though the abbreviations are not the
				 * same. While WooCommerce stores an abbreviation of country and
				 * state in user metadata, the CiviCRM API accepts the full name
				 * and id of different states and countries.
				 */

				$_civi_state_id = $objectRef[$value];

				// get the country id.
				if ( isset( $objectRef['country_id'] ) AND $objectRef['country_id'] != 'null' ) {
					$_civi_country_id = $objectRef['country_id'];
				} else {
					// if no country id is provided, the state can not be set.
					continue;
				}

				// CiviCRM API v3 is not supporting state province at present.
				$query = 'SELECT abbreviation FROM civicrm_state_province WHERE country_id = %1 AND id = %2';
				$params = array(
					1 => array( $_civi_country_id, 'Integer' ),
					2 => array( $_civi_state_id, 'Integer' ),
				);

				$_state_abbreviation = CRM_Core_DAO::singleValueQuery( $query, $params );

				update_user_meta( $user_id, $_address_type . '_' . $key, $_state_abbreviation );

				continue;

			} elseif ( $key == 'country' AND isset( $objectRef[$value] ) AND $objectRef[$value] != 'null' AND ! $_is_deletion ) {

				$_civi_country_id = $objectRef[$value];

				// get the country abbreviation
				$result = civicrm_api3( 'Country', 'get', array(
					'sequential' => 1,
					'return' => array( 'iso_code' ),
					'id' => $_civi_country_id,
				) );

				update_user_meta( $user_id, $_address_type . '_' . $key, $result['values'][0]['iso_code'] );

				continue;

			}

			$_value_to_write = ( $_is_deletion ) ? '' : $objectRef[$value];

			update_user_meta( $user_id, $_address_type . '_' . $key, $_value_to_write );

		}

	}



	/**
	 * Add WordPress WooCommerce sync hooks.
	 *
	 * @since 0.3
	 */
	private function _add_hooks_wp_wc() {

		// hook into meta data update process
		add_action( 'updated_user_meta', array( $this, 'update_civi_address_fields' ), 100, 4 );
		add_action( 'added_user_meta', array( $this, 'update_civi_address_fields' ), 100, 4 );

	}



	/**
	 * Remove WordPress WooCommerce sync hooks.
	 *
	 * @since 0.3
	 */
	private function _remove_hooks_wp_wc() {

		remove_action( 'updated_user_meta', array( $this, 'update_civi_address_fields' ), 100 );
		remove_action( 'added_user_meta', array( $this, 'update_civi_address_fields' ), 100 );

	}



	/**
	 * Add CiviCRM WooCommerce sync hooks.
	 *
	 * @since 0.3
	 */
	private function _add_hooks_civi_wc() {

		// hook into post process of address update in CiviCRM for synchronisation to WordPress/WooCommerce.
		add_action( 'civicrm_pre', array( $this, 'civi_primary_n_billing_addresses_update' ), 10, 4 );

		// hook into post process of Phone update in CiviCRM for synchronisation to WordPress/WooCommerce.
		add_action( 'civicrm_pre', array( $this, 'civi_primary_phone_update' ), 10, 4 );

	}



	/**
	 * Remove CiviCRM WooCommerce sync hooks.
	 *
	 * @since 0.3
	 */
	private function _remove_hooks_civi_wc() {

		remove_action( 'civicrm_pre', array( $this, 'civi_primary_n_billing_addresses_update' ), 10 );
		remove_action( 'civicrm_pre', array( $this, 'civi_primary_phone_update' ), 10 );

	}

} // class ends



