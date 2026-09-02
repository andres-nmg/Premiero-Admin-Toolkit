<?php
/**
 * Coordinador del modulo de backups remotos.
 *
 * @package Premiero_Admin_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Premiero_Remote_Backups {

	const CRON_SCAN = 'premiero_remote_backups_scan';
	const CRON_SCAN_SOON = 'premiero_remote_backups_scan_soon';
	const CRON_WORKER = 'premiero_remote_backups_worker';
	const SCHEDULE_SCAN = 'premiero_remote_backups_15_minutes';

	private static $initialized = false;

	public static function init() {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_action( self::CRON_SCAN, array( __CLASS__, 'run_scan' ) );
		add_action( self::CRON_SCAN_SOON, array( __CLASS__, 'run_scan' ) );
		add_action( self::CRON_WORKER, array( 'Premiero_Backup_Worker', 'run' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_install_and_schedule' ), 35 );
		Premiero_Backup_Detector::init();
		Premiero_Remote_Backup_Settings::init();
	}

	public static function activate() {
		Premiero_Backup_Sync_Queue::install();
		self::refresh_schedule();
	}

	public static function deactivate() {
		self::clear_schedule();
		Premiero_Backup_Worker::release_lock();
	}

	public static function cron_schedules( $schedules ) {
		$schedules[ self::SCHEDULE_SCAN ] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => 'Cada 15 minutos (Premiero Backups)',
		);
		return $schedules;
	}

	public static function maybe_install_and_schedule() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		Premiero_Backup_Sync_Queue::maybe_install();
		self::refresh_schedule( false );
	}

	public static function refresh_schedule( $schedule_scan = true ) {
		if ( ! Premiero_Remote_Backup_Settings::is_enabled() ) {
			self::clear_schedule();
			return;
		}
		if ( ! wp_next_scheduled( self::CRON_SCAN ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, self::SCHEDULE_SCAN, self::CRON_SCAN );
		}
		if ( $schedule_scan ) {
			self::schedule_scan_soon( 5 );
		}
	}

	public static function run_scan() {
		Premiero_Backup_Detector::scan();
		Premiero_Backup_Reconciler::run();
		if ( Premiero_Backup_Sync_Queue::has_ready_items() ) {
			self::schedule_worker( 5 );
		}
	}

	public static function schedule_scan_soon( $delay = 60 ) {
		if ( ! Premiero_Remote_Backup_Settings::is_enabled() ) {
			return;
		}
		self::schedule_single_earliest( self::CRON_SCAN_SOON, $delay );
	}

	public static function schedule_worker( $delay = 1 ) {
		if ( ! Premiero_Remote_Backup_Settings::is_enabled() ) {
			return;
		}
		self::schedule_single_earliest( self::CRON_WORKER, $delay );
	}

	private static function schedule_single_earliest( $hook, $delay ) {
		$target   = time() + max( 1, (int) $delay );
		$existing = wp_next_scheduled( $hook );
		if ( $existing && (int) $existing <= $target ) {
			return;
		}
		if ( $existing ) {
			wp_unschedule_event( $existing, $hook );
		}
		wp_schedule_single_event( $target, $hook );
	}

	private static function clear_schedule() {
		wp_clear_scheduled_hook( self::CRON_SCAN );
		wp_clear_scheduled_hook( self::CRON_SCAN_SOON );
		wp_clear_scheduled_hook( self::CRON_WORKER );
	}
}
