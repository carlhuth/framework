<?php

class extensions {
	/** The config
	 * array(section=>array(extension=>array( array('basetag'=>basetag,'tag'=>tag,'addpri'=>addpri,'cmd'=>cmd) )))
	 * ( $_exts[$section][$extension][$priority] )
	 */
	var $_exts;
	
	/** Hints 
	 * special cases of priorities
	 */
	var $_hints;
	
	var $_sorted;
	
	/** The filename to write this configuration to
	*/
	function get_filename() {
		return "extensions_additional.conf";
	}
	
	/** Add an entry to the extensions file
	* @param $section    The section to be added to
	* @param $extension  The extension used
	* @param $tag        A tag to use (to reference with basetag), use false or '' if none
	* @param $command    The command to execute
	* @param $basetag    The tag to base this on. Only used in conjunction with $addpriority
	*                    priority. Defaults to false.
	* @param $addpriority  Finds the priority of the tag called $basetag, and adds this 
	*			value to it to use as the priority for this command.
	* @return 
	*/
	function add($section, $extension, $tag, $command, $basetag = false, $addpriority = false) {
		
		if ($basetag || $addpriority) {
			if (!is_int($addpriority) || ($addpriority < 1)) {
				trigger_error(E_ERROR, "\$addpriority must be >= 1 in extensions::add()");
				return false;
			}
			if (!isset($basetag)) {
				trigger_error(E_ERROR, "\$basetag is required with \$addpriority in extensions::add()");
				return false;
			}
		}
		
		if (!isset($basetag)) {
			// no basetag, we need to make one
			
			if (!isset($this->_exts[$section][$extension])) {
				// first entry, use 1
				$basetag = '1';
			} else {
				// anything else just n
				$basetag = 'n';
			}
		}
		
		$new = array(
			'basetag' => $basetag,
			'tag' => $tag,
			'addpri' => $addpriority,
			'cmd' => $command,
		);
		
		$this->_exts[$section][$extension][] = $new;
	}
	
	/** Sort sections, extensions and priorities alphabetically
	 */
	function sort() {
		foreach (array_keys($this->_exts) as $section) {
			foreach (array_keys($this->_exts[$section]) as $extension) {
				// sort priorities
				ksort($this->_exts[$section][$extension]);
			}
			// sort extensions
			ksort($this->_exts[$section]);
		}
		// sort sections
		ksort($this->_exts);
		
		$this->_sorted = true;
	}
	
	function addHint($section, $extension, $hintvalue) {
		$this->_hints[$section][$extension][] = $hintvalue;
	}
	
	function addGlobal($globvar, $globval) {
		$this->_globals[$globvar] = $globval;
	}
	
	function addInclude($section, $incsection) {
		$this->_includes[$section][] = $incsection;
	}
	
	/* This function allows new priorities to be injected into already generated dialplan
	*  usage: $ext->splice($context, $exten, $priority_number, new ext_goto('1','s','ext-did'));
	*/
	function splice($section, $extension, $priority, $command) {
		if($priority == 0) {
			$basetag = '1';
			// we'll be defining a new pri "1", so change existing "1" to "n"
			$this->_exts[$section][$extension][0]['basetag'] = 'n';
		} else {
			$basetag = 'n';
		}
		$newcommand = array(
			'basetag' => $basetag,
			'tag' => '',
			'addpri' => '',
			'cmd' => $command
		);

		/* This little routine from http://ca.php.net/array_splice overcomes 
		*  problems that array_splice has with multidmentional arrays
		*/
			$array = $this->_exts[$section][$extension];
			$ky = $priority;
			$val = $newcommand;
			$n = $ky; 
			 foreach($array as $key => $value) 
			   { 
				$backup_array[$key] = $array[$key]; 
			   } 
			 $upper_limit = count($array); 
			 while($n <= $upper_limit) 
			   { 
				if($n == $ky) 
				  { 
			 $array[$n] = $val; 
			// echo $n; 
				  } 
				else 
				  { 
			 $i = $n - "1"; 
			 $array[$n] = $backup_array[$i]; 
				  } 
				$n++; 
			   } 
		
		// apply our newly modified array
		//echo "Splicing [$section] $extension\n";
		$this->_exts[$section][$extension] = $array;		
		
		//print_r($this->_exts[$section][$extension]);
	}
	
	/** Generate the file
	* @return A string containing the extensions.conf file
	*/
	function generateConf() {
		$output = "";
		
		/* sorting is not necessary anymore
		if (!$this->_sorted) {
			$this->sort();
		}
		*/
		
		//var_dump($this->_exts);
		
		//take care of globals first
		if(isset($this->_globals) && is_array($this->_globals)){
			$output .= "[globals]\n";
			$output .= "#include globals_custom.conf\n";
			foreach (array_keys($this->_globals) as $global) {
				$output .= $global." = ".$this->_globals[$global]."\n";
			}
			$output .= "\n\n;end of [globals]\n\n\n";
		}
		
		//now the rest of the contexts
		if(is_array($this->_exts)){
			foreach (array_keys($this->_exts) as $section) {
				$output .= "[".$section."]\n";
				
				//automatically include a -custom context
				$output .= "include => {$section}-custom\n";
				//add requested includes for this context
				if (isset($this->_includes[$section])) {
					foreach ($this->_includes[$section] as $include) {
						$output .= "include => ".$include."\n";
					}
				}
				
				foreach (array_keys($this->_exts[$section]) as $extension) {
					foreach (array_keys($this->_exts[$section][$extension]) as $idx) {
					
						$ext = $this->_exts[$section][$extension][$idx];
						
						//echo "[$section] $extension $idx\n";
						//var_dump($ext);
							
						$output .= "exten => ".$extension.",".
							$ext['basetag'].
							($ext['addpri'] ? '+'.$ext['addpri'] : '').
							($ext['tag'] ? '('.$ext['tag'].')' : '').
							",".$ext['cmd']->output()."\n";
					}
					if (isset($this->_hints[$section][$extension])) {
						foreach ($this->_hints[$section][$extension] as $hint) {
							$output .= "exten => ".$extension.",hint,".$hint."\n";
						}
					}
				}
				
				$output .= "\n; end of [".$section."]\n\n\n";
			}
		}
		
		return $output;
	}

	/** Generate the file
	* @return A string containing the extensions.conf file
	*/
	function generateOldConf() {
		$output = "";
		
		/* sorting is not necessary anymore
		if (!$this->_sorted) {
			$this->sort();
		}
		*/
		
		var_dump($this->_exts);
		
		foreach (array_keys($this->_exts) as $section) {
			$output .= "[".$section."]\n";
			
			foreach (array_keys($this->_exts[$section]) as $extension) {
				$priority = 0;
				$prioritytable = array();
				
				foreach (array_keys($this->_exts[$section][$extension]) as $idx) {
				
					$ext = $this->_exts[$section][$extension][$idx];
					
					//var_dump($ext);
					switch ($ext['basetag']) {
						case '1': $priority = 1; break;
						case 'n': $priority += 1; break;
						default:
							if (isset($prioritytable[$ext['basetag']])) {
								$priority = $prioritytable[$ext['basetag']];
							} else {
								$priority = 'unknown!!!';
							}
						break;
					}
					
					if ($ext['addpri']) {
						$priority += $ext['addpri'];
					}
					
					if ($ext['tag']) {
						$prioritytable[$ext['tag']] = $priority;
					}
					
					$output .= "exten => ".$extension.",".$priority.
						",".$ext['cmd']->output()."\n";
					
				}
				
				if (isset($this->_hints[$section][$extension])) {
					foreach ($this->_hints[$section][$extension] as $hint) {
						$output .= "exten => ".$extension.",hint,".$hint;
					}
				}
			}
			
			$output .= "\n; end of [".$section."]\n\n\n";
		}
		
		return $output;
	}
}

class extension { 
	var $data;
	
	function extension($data) {
		$this->data = $data;
	}
	
	function incrementContents($value) {
		return true;
	}
	
	function output() {
		return $data;
	}
}

class ext_goto extends extension {
	var $pri;
	var $ext;
	var $context;
	
	function ext_goto($pri, $ext = false, $context = false) {
		if ($context !== false && $ext === false) {
			trigger_error(E_ERROR, "\$ext is required when passing \$context in ext_goto::ext_goto()");
		}
		
		$this->pri = $pri;
		$this->ext = $ext;
		$this->context = $context;
	}
	
	function incrementContents($value) {
		$this->pri += $value;
	}
	
	function output() {
		return 'Goto('.($this->context ? $this->context.',' : '').($this->ext ? $this->ext.',' : '').$this->pri.')' ;
	}
}

class ext_gotoif extends extension {
	var $true_priority;
	var $false_priority;
	var $condition;
	function ext_gotoif($condition, $true_priority, $false_priority = false) {
		$this->true_priority = $true_priority;
		$this->false_priority = $false_priority;
		$this->condition = $condition;
	}
	function output() {
		return 'GotoIf(' .$this->condition. '?' .$this->true_priority.($this->false_priority ? ':' .$this->false_priority : '' ). ')' ;
	}
	function incrementContents($value) {
		$this->true_priority += $value;
		$this->false_priority += $value;
	}
}

class ext_gotoiftime extends extension {
	var $true_priority;
	var $condition;
	function ext_gotoiftime($condition, $true_priority) {
		$this->true_priority = $true_priority;
		$this->condition = $condition;
	}
	function output() {
		return 'GotoIfTime(' .$this->condition. '?' .$this->true_priority. ')' ;
	}
	function incrementContents($value) {
		$this->true_priority += $value;
	}
}

class ext_noop extends extension {
	function output() {
		return "Noop(".$this->data.")";
	}
}

class ext_dial extends extension {
	var $number;
	var $options;
	
	function ext_dial($number, $options = "tr") {
		$this->number = $number;
		$this->options = $options;
	}
	
	function output() {
		return "Dial(".$this->number.",".$this->options.")";
	}
}

class ext_setvar {
	var $var;
	var $value;
	
	function ext_setvar($var, $value) {
		$this->var = $var;
		$this->value = $value;
	}
	
	function output() {
		return "Set(".$this->var."=".$this->value.")";
	}
}

class ext_wait extends extension {
	function output() {
		return "Wait(".$this->data.")";
	}
}

class ext_answer extends extension {
	function output() {
		return "Answer";
	}
}

class ext_privacymanager extends extension {
	function output() {
		return "PrivacyManager";
	}
}

class ext_macro {
	var $macro;
	var $args;
	
	function ext_macro($macro, $args='') {
		$this->macro = $macro;
		$this->args = $args;
	}
	
	function output() {
		return "Macro(".$this->macro.",".$this->args.")";
	}
}

class ext_setcidname extends extension {
	function output() {
		return "Set(CALLERID(name)=".$this->data.")";
	}
}

class ext_record extends extension {
	function output() {
		return "Record(".$this->data.")";
	}
}

class ext_playback extends extension {
	function output() {
		return "Playback(".$this->data.")";
	}
}

class ext_queue {
	var $var;
	var $value;
	
	function ext_queue($queuename, $options, $optionalurl, $announceoverride, $timeout) {
		$this->queuename = $queuename;
		$this->options = $options;
		$this->optionalurl = $optionalurl;
		$this->announceoverride = $announceoverride;
		$this->timeout = $timeout;
	}
	
	function output() {
		// for some reason the Queue cmd takes an empty last param (timeout) as being 0
		// when really we want unlimited
		if ($this->timeout != "")
			return "Queue(".$this->queuename."|".$this->options."|".$this->optionalurl."|".$this->announceoverride."|".$this->timeout.")";
		else
			return "Queue(".$this->queuename."|".$this->options."|".$this->optionalurl."|".$this->announceoverride.")";
	}
}

class ext_hangup extends extension {
	function output() {
		return "Hangup";
	}
}

class ext_digittimeout extends extension {
	function output() {
		return "Set(TIMEOUT(digit)=".$this->data.")";
	}
}

class ext_responsetimeout extends extension {
	function output() {
		return "Set(TIMEOUT(response)=".$this->data.")";
	}
}

class ext_background extends extension {
	function output() {
		return "Background(".$this->data.")";
	}
}

class ext_read {
	var $astvar;
	var $filename;
	var $maxdigits;
	var $option;
	
	function ext_read($astvar, $filename='', $maxdigits='', $option='') {
		$this->astvar = $astvar;
		$this->filename = $filename;
		$this->maxdigits = $maxdigits;
		$this->option = $option;
	}
	
	function output() {
		return "Read(".$this->astvar.",".$this->filename.",".$this->maxdigits.",".$this->option.")";
	}
}

class ext_meetme {
	var $confno;
	var $options;
	var $pin;
	
	function ext_meetme($confno, $options='', $pin='') {
		$this->confno = $confno;
		$this->options = $options;
		$this->pin = $pin;
	}
	
	function output() {
		return "MeetMe(".$this->confno.",".$this->options.",".$this->pin.")";
	}
}

class ext_authenticate {
	var $pass;
	var $options;
	
	function ext_authenticate($pass, $options='') {
		$this->pass = $pass;
		$this->options = $options;
	}
	
	function output() {
		return "Authenticate(".$this->pass.",".$this->options.")";
	}
}

class ext_page extends extension {
	function output() {
		return "Page(".$this->data.")";
	}
}

class ext_disa extends extension {
	function output() {
		return "DISA(".$this->data.")";
	}
}
class ext_agi extends extension {
	function output() {
		return "AGI(".$this->data.")";
	}
}
class ext_dbdel extends extension {
	function output() {
		return "dbDel(".$this->data.")";
	}
}
class ext_vmmain extends extension {
	function output() {
		return "VoiceMailMain(".$this->data.")";
	}
}
class ext_vm extends extension {
	function output() {
		return "VoiceMail(".$this->data.")";
	}
}
class ext_saydigits extends extension {
	function output() {
		return "SayDigits(".$this->data.")";
	}
}
class ext_sayunixtime extends extension {
	function output() {
		return "SayUnixTime(".$this->data.")";
	}
}
class ext_echo extends extension {
	function output() {
		return "Echo(".$this->data.")";
	}
}
// Thanks to agillis for the suggestion of the nvfaxdetect option
class ext_nvfaxdetect extends extension {
	function output() {
		return "NVFaxDetect(".$this->data.")";
	}
}
class ext_playtones extends extension {
	function output() {
		return "Playtones(".$this->data.")";
	}
}
class ext_zapbarge extends extension {
	function output() {
		return "ZapBarge(".$this->data.")";
	}
}
class ext_sayalpha extends extension {
	function output() {
		return "SayAlpha(".$this->data.")";
	}
}

class ext_system extends extension {
	function output() {
		return "System(".$this->data.")";
	}
}

/* example usage
$ext = new extensions;


$ext->add('default','123', 'dial1', new ext_dial('ZAP/1234'));
$ext->add('default','123', '', new ext_noop('test1'));
$ext->add('default','123', '', new ext_noop('test2'));
$ext->add('default','123', '', new ext_noop('test at +101'), 'dial1', 101);
$ext->add('default','123', '', new ext_noop('test at +102'));
echo "<pre>";
echo $ext->generateConf();
echo $ext->generateOldConf();
exit;
*/

/*
exten => 123,1(dial1),Dial(ZAP/1234)
exten => 123,n,noop(test1)
exten => 123,n,noop(test2)
exten => 123,dial1+101,noop(test at 101)
exten => 123,n,noop(test at 102)
*/

?>
