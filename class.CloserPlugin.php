<?php
require_once (INCLUDE_DIR . 'class.plugin.php');
require_once (INCLUDE_DIR . 'class.signal.php');
require_once (INCLUDE_DIR . 'class.canned.php');
require_once ('config.php');

/**
 * The goal of this Plugin is to close tickets when they get old.
 * Logans Run style.
 */
class CloserPlugin extends Plugin {
	var $config_class = 'CloserPluginConfig';
	
	/**
	 * Set to TRUE to enable extra logging.
	 *
	 * @var boolean
	 */
	const DEBUG = TRUE;
	
	/**
	 * Hook the bootstrap process
	 *
	 * Run on every instantiation, so needs to be concise.
	 *
	 * {@inheritdoc}
	 *
	 * @see Plugin::bootstrap()
	 */
	public function bootstrap() {
		// Listen for cron Signal, which only happens at end of class.cron.php:
		Signal::connect ( 'cron', function ($ignored, $data) {
			
			// Autocron is an admin option, we can filter out Autocron Signals
			// to ensure changing state for potentially hundreds/thousands
			// of tickets doesn't affect interactive Agent/User experience.
			$use_autocron = $this->getConfig ()->get ( 'use_autocron' );
			
			// Autocron Cron Signals are sent with this array key set to TRUE
			$is_autocron = (isset ( $data ['autocron'] ) && $data ['autocron']);
			
			// Normal cron isn't Autocron:
			if (! $is_autocron || ($use_autocron && $is_autocron))
				$this->logans_run_mode ();
		} );
	}
	
	/**
	 * Closes old tickets..
	 * with extreme prejudice.. or, regular prejudice.. whatever.
	 *
	 * = Welcome to the 23rd Century.
	 * The perfect world of total pleasure.
	 *
	 * ... there's just one catch.
	 */
	private function logans_run_mode() {
		$config = $this->getConfig ();
		
		// We can store arbitrary things in the config, like, when we ran this last:
		$last_run = $config->get ( 'last-run' );
		$now = time (); // Assume server timezone doesn't change enough to break this
		$config->set ( 'last-run', $now );
		
		// assume a freqency of "Every Cron" means it is always overdue
		$next_run = 0;
		
		// Convert purge frequency to a comparable format to timestamps:
		if ($freq_in_config = ( int ) $config->get ( 'purge-frequency' )) {
			// Calculate when we want to run next, config hours into seconds,
			// plus the last run is the timestamp of the next scheduled run
			$next_run = $last_run + ($freq_in_config * 60 * 60);
		}
		
		// See if it's time to check old tickets
		// Always run when in DEBUG mode.. because waiting for the scheduler is slow
		// If we don't have a next_run, it's because we want it to run
		// If the next run is in the past, then we are overdue, so, lets go!
		if (self::DEBUG || ! $next_run || $now > $next_run) {
			$max_per_run = ( int ) $config->get ( 'purge-num' );
			
			// Use the number of config groups to run the closer as many times as is needed.
			foreach ( range ( 1, CloserPluginConfig::NUMBER_OF_SETTINGS ) as $group_id ) {
				if (! $config->get ( 'group-enabled-' . $group_id )) {
					if (self::DEBUG)
						error_log ( "Group $group_id is not enabled." );
					continue;
				} elseif (self::DEBUG) {
					error_log ( "Running group $group_id" );
				}
				
				try {
					
					// Build an array of settings for this task:
					// fetch config for finder:
					$age_days = ( int ) $config->get ( 'purge-age-' . $group_id );
					$onlyAnswered = ( bool ) $config->get ( 'close-only-answered-' . $group_id );
					$onlyOverdue = ( bool ) $config->get ( 'close-only-overdue-' . $group_id );
					
					if (self::DEBUG)
						$group_name = $config->get ( 'group-name-' . $group_id ); // for logging
					$from_status = ( int ) $config->get ( 'from-status-' . $group_id );
					$to_status = ( int ) $config->get ( 'to-status-' . $group_id );
					
					// Find tickets that we might need to work on:
					$open_ticket_ids = $this->findTicketIds ( $from_status, $age_days, $max_per_run, $onlyAnswered, $onlyOverdue );
					if (self::DEBUG)
						error_log ( "CloserPlugin group [$group_id] $group_name has " . count ( $open_ticket_ids ) . " open tickets." );
						
						// Bail if there's no work to do
					if (! count ( $open_ticket_ids ))
						continue; // Not return, as the next group might have work.
							          
					// Gather the resources required to start changing statuses:
					$new_status = TicketStatus::lookup ( $to_status );
					$admin_note = $config->get ( 'admin-note-' . $group_id ) ?: FALSE;
					$admin_reply = ($config->get ( 'admin-reply-' . $group_id )) ? Canned::lookup ( $config->get ( 'admin-reply-' . $group_id ) ) : FALSE;
					
					if ($admin_reply)
						// Fetch the actual content of the reply:
						$admin_reply = $admin_reply->getFormattedResponse ( 'html' );
					
					if (self::DEBUG)
						print "Found the following details:\nAdmin Note: $admin_note\n\nAdmin Reply: $admin_reply\n";
						
						// Go through the tickets:
					foreach ( $open_ticket_ids as $ticket_id ) {
						
						// Fetch the ticket as an Object
						$ticket = Ticket::lookup ( $ticket_id );
						if (! $ticket instanceof Ticket) {
							error_log ( "Ticket $ticket_id is not instatiable. :-(" );
							continue;
						}
						
						// Some tickets aren't closeable.. either because of open tasks, or missing fields.
						// we can therefore only work on closeable tickets.
						if (! $ticket->isCloseable ()) {
							$ticket->LogNote ( __ ( 'Error auto-changing status' ), __ ( 'Unable to change this ticket\'s status to ' . $new_status->getState () ), 'AutoCloser Plugin', FALSE );
							continue;
						}
						
						// Add a Note to the thread indicating it was closed by us
						if ($admin_note)
							$ticket->LogNote ( __ ( 'Changing status to: ' . $new_status->getState () ), $admin_note, 'AutoCloser Plugin', FALSE ); // Posts Note as AutoCloser Plugin with no email alert
								                                                                                                                        
						// Post Reply to the user, telling them the ticket is closed, relates to issue #2
						if ($admin_reply)
							$this->postReply ( $ticket, $new_status, $admin_reply );
						
						$this->changeTicketStatus ( $ticket, $new_status );
						$done ++;
					}
				} catch ( Exception $e ) {
					// Well, something borked
					error_log ( "Exception encountered, we'll soldier on, but something is broken!" );
					error_log ( $e->getMessage () );
				}
			}
		}
	}
	
	/**
	 * This is the part that actually "Closes" the tickets
	 *
	 * Well, depending on the admin settings I mean.
	 *
	 * Could use $ticket->setStatus($closed_status) function
	 * however, this gives us control over _how_ it is closed.
	 * preventing accidentally making any logged-in staff
	 * associated with the closure, which is an issue with AutoCron
	 *
	 * @param Ticket $ticket        	
	 * @param TicketStatus $new_status        	
	 */
	private function changeTicketStatus($ticket, $new_status) {
		if (self::DEBUG)
			error_log ( "Setting status " . $new_status->getState () . " for ticket {$ticket->getId()}::{$ticket->getSubject()}" );
			
			// Start by setting the last update and closed timestamps to now
		$ticket->closed = $ticket->lastupdate = SqlFunction::NOW ();
		
		// Remove any duedate or overdue flags
		$ticket->duedate = null;
		$ticket->clearOverdue ( FALSE ); // flag prevents saving, we'll do that
		                                 
		// Post an Event with the current timestamp.
		$ticket->logEvent ( $new_status->getState (), array (
				'status' => array (
						$new_status->getId (),
						$new_status->getName () 
				) 
		) );
		// Actually apply the new "TicketStatus" to the Ticket.
		$ticket->status = $new_status;
		
		// Save it, flag prevents it refetching the ticket data straight away (inefficient)
		$ticket->save ( FALSE );
	}
	
	/**
	 * Retrieves an array of ticket_id's from the database
	 *
	 * Filtered to only show those that are still open for more than $age_days, oldest first.
	 *
	 * Could be made static so other classes can find old tickets..
	 *
	 * @param int $from_status
	 *        	the id of the status to select tickets from
	 * @param int $age_days
	 *        	admin configuration max-age for an un-updated ticket.
	 * @param int $max
	 *        	don't find more than this many at once
	 * @param bool $onlyAnswered
	 *        	set to true to filter tickets to only those that have an agent answer
	 * @param bool $onlyOverdue
	 *        	set to true to filter tickets to only those that are overdue
	 * @return array of integers that are Ticket::lookup compatible ID's of Open Tickets
	 * @throws Exception so you have something interesting to read in your cron logs..
	 */
	private function findTicketIds($from_status, $age_days, $max, $onlyAnswered = FALSE, $onlyOverdue = FALSE) {
		if (! $from_status)
			throw new \Exception ( "Invalid parameter (int) from_status needs to be > 0" );
		
		if ($age_days < 1)
			throw new \Exception ( "Invalid parameter (int) age_days needs to be > 0" );
		
		if ($max < 1)
			throw new \Exception ( "Invalid parameter (int) max needs to be > 0" );
		
		$whereFilter = '';
		
		if ($onlyAnswered)
			$whereFilter .= ' AND isanswered=1';
		
		if ($onlyOverdue)
			$whereFilter .= ' AND isoverdue=1';
			
			// Ticket query, note MySQL is doing all the date maths:
			// Sidebar: Why haven't we moved to PDO yet?
		$sql = sprintf ( "
SELECT ticket_id 
FROM %s WHERE lastupdate < DATE_SUB(NOW(), INTERVAL %d DAY)
AND status_id=%d %s
ORDER BY ticket_id ASC
LIMIT %d", TICKET_TABLE, $age_days, $from_status, $whereFilter, $max );
		
		if (self::DEBUG)
			error_log ( "Looking for tickets with query: $sql" );
		
		$r = db_query ( $sql );
		
		// Fill an array with just the ID's of the tickets:
		$ids = array ();
		while ( $i = db_fetch_array ( $r, MYSQLI_ASSOC ) )
			$ids [] = $i ['ticket_id'];
		
		return $ids;
	}
	
	/**
	 * Sends a reply to the ticket creator
	 *
	 * Wrapper/customizer around the Ticket::postReply method.
	 *
	 * @param Ticket $ticket        	
	 * @param TicketStatus $new_status        	
	 * @param string $admin_reply        	
	 */
	function postReply($ticket, $new_status, $admin_reply) {
		// We can re-use the robot to send notifications for multiple tickets
		// no point rebuilding the object for each one.
		static $robot;
		if (! isset ( $robot )) {
			$robot = $this->getConfig ()->get ( 'robot-account' );
			if ($robot)
				$robot = Staff::lookup ( $robot );
		}
		
		// Override some assumptions about who is staff..
		// cron calls are generally offline, so this won't matter
		// also, autocron happens after, not during an interactive users
		// actions, so, this shouldn't break things.. right?
		global $thisstaff;
		
		// What if nobody is assigned to the ticket? Do we still want to close it?
		// If the admin has selected "ONLY send as Ticket's assigned staff", then
		// do that.
		if ($robot) {
			// Use the robot account the admin selected.
			$assignee = $robot;
		} else {
			$assignee = $ticket->getAssignee ();
			if (! $assignee instanceof Staff) {
				// well poo
				// now what do?
				$ticket->logNote ( __ ( 'AutoCloser Error' ), __ ( 'Unable to send reply, no assigned Agent on ticket, and no Robot account specified in config.' ), 'AutoCloser Plugin', FALSE );
				return;
			}
		}
		
		// Make osTicket think our patsy is actually logged in:
		// This actually bypasses any authentication/validation checks..
		$thisstaff = $assignee;
		
		// Replace any ticket variables in the message:
		$variables = array (
				'recipient' => $ticket->getOwner () 
		); // Only send to the original ticket creator.
		   
		// Provide extra variables.. because. :-)
		$options = array (				
				'wholethread' => 'fetchWholeThread', // Must call the whole thread first, otherwise the response thread request is cached.
				'firstresponse' => 'fetchFirstResponse',
				'lastresponse' => 'fetchLastResponse,' 
		);
		// See if they've been used, if so, call the function
		foreach ( $optionptions as $option => $method ) {
			if (strpos ( $admin_reply, $option ) !== FALSE) {
				$variables [$option] = call_user_func ( array (
						$this,
						$method 
				), $ticket );
			}
		}
		
		$custom_reply = $ticket->replaceVars ( $admin_reply, $variables );
		
		// Build an array of values to send to the ticket's postReply function
		// Actually, with the $assignee/$robot as $thisstaff above, we don't need most of this
		$vars = array (
				// 'poster' => $assignee, // our patsy from above
				// 'staffId' => $assignee->getId(),
				'response' => $custom_reply 
		); // the "response" is our text after replacing any vars
		   // 'signature' => 'dept', // Set the department signature, if available
		   // 'emailcollab' => FALSE // don't send notification to all collaborators.. maybe.. dunno.
		   
		// We ignore any posting errors, but it takes an array anyway
		$errors = array ();
		
		// Send the alert without claiming the ticket on our assignee's behalf.
		if (! $sent = $ticket->postReply ( $vars, $errors, TRUE, FALSE ))
			// doh, the message didn't work.
			// we still have to change the status though, so, we'll just post another logNote warning of the error:
			$ticket->LogNote ( __ ( 'Error Notification' ), __ ( 'We were unable to post a reply to the ticket creator.' ), 'AutoCloser Plugin', FALSE );
	}
	/**
	 * Fetches the first response sent to the ticket Owner
	 *
	 * @param Ticket $ticket        	
	 * @return string
	 */
	private function fetchFirstResponsee(Ticket $ticket) {
		$all_responses = $ticket->getThreadEntries ( 'R' );
		$response = reset ( $all_responses );
		if ($response instanceof ResponseThreadEntry)
			return $response->getBody ();
	}
	
	/**
	 * Fetches the last response sent to the ticket Owner.
	 *
	 * @param Ticket $ticket        	
	 * @return string
	 */
	private function fetchLastResponsee(Ticket $ticket) {
		$all_responses = $ticket->getThreadEntries ( 'R' );
		$response = end ( $all_responses );
		if ($response instanceof ResponseThreadEntry)
			return $response->getBody ();
	}
	
	/**
	 * Fetches the whole thread that the client can see.
	 *
	 * As an HTML message.
	 *
	 * @param Ticket $ticket        	
	 * @return string
	 */
	private function fetchWholeThread(Ticket $ticket) {
		$msg = '';
		foreach ( $ticket->getClientThread () as $te ) {
			if ($te instanceof ThreadEntry)
				$msg .= $te->getBody ();
		}
		return $msg;
	}
	
	/**
	 * Required stub.
	 *
	 * {@inheritdoc}
	 *
	 * @see Plugin::uninstall()
	 */
	function uninstall() {
		$errors = array ();
		global $ost;
		$ost->alertAdmin ( 'Plugin: Closer has been uninstalled', "Old open tickets will remain active.", true );
		
		parent::uninstall ( $errors );
	}
	
	/**
	 * Plugins seem to want this.
	 */
	public function getForm() {
		return array ();
	}
}