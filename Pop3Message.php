<?php
/**
Pop3 Message
Requires http://www.phpclasses.org/package/2-PHP-Access-to-e-mail-mailboxes-using-the-POP3-protocol.html
*/

class Pop3Message extends DataObject {

	static $db = array(
		'Subject' => 'Varchar(255)',
		'Date' => 'SSDatetime',
		'From_address' => 'Varchar(255)',
		'From_name' => 'Varchar(255)',
		'To_address' => 'Varchar(255)',
		'To_name' => 'Varchar(255)',
		'MessageBody' => 'HTMLText',
		'Type' => 'Varchar(255)',
		'SubType' => 'Varchar(255)',
		'RawHeaders' => 'Text',
		'RawBody' => 'Text',	
		'PopHostname' => 'Varchar(255)',
		'PopUsername' => 'Varchar(255)',			
	);
	
	static $has_one = array(
		'InternalMessage' => 'Message',
		'Log' => 'Pop3Message_Log'
	);
	
	static $has_many = array(
		'Attachments' => 'Pop3Message_Attachment'
	);
	

	static $hostname;
	static $port;
	static $tls = 1;
	static $username;
	static $password;
	static $maildomain;
	
	public function requireDefaultRecords() {
		if(!DataObject::get_one('Group', "Code = 'pop3senders'")) { 
			$group = new Group(); 
			$group->Title = 'Pop3Senders'; 
			$group->Code = 'pop3senders'; 
			$group->write(); 
			
			Database::alteration_message('Group Pop3Senders has been created.',"created");
		}
	
	}  
	
	/**
	Basic Message List
	For debugging.
	For production, use getmail
	*/
	function messagelist(){
		$base = Director::baseFolder() . "/libs/code/pop3_mimeparser/";
		require_once($base . "pop3.php");
	
		stream_wrapper_register('pop3', 'pop3_stream');  /* Register the pop3 stream handler class */
	
		$pop3=new pop3_class;
		$pop3->hostname= Pop3Message::$hostname;             /* POP 3 server host name                      */
		$pop3->port= Pop3Message::$port;                         /* POP 3 server host port,
		                                            usually 110 but some servers use other ports
		                                            Gmail uses 995                              */
		$pop3->tls= Pop3Message::$tls;                            /* Establish secure connections using TLS      */
		$user= Pop3Message::$username;                        /* Authentication user name                    */
		$password= Pop3Message::$password;                    /* Authentication password                     */
		$pop3->realm="";                         /* Authentication realm or domain              */
		$pop3->workstation="";                   /* Workstation for NTLM authentication         */
		$apop=0;                                 /* Use APOP authentication                     */
		$pop3->authentication_mechanism="USER";  /* SASL authentication mechanism               */
		$pop3->debug=0;                          /* Output debug information                    */
		$pop3->html_debug=1;                     /* Debug information is in HTML                */
		$pop3->join_continuation_header_lines=1; /* Concatenate headers split in multiple lines */
		

		if(($error=$pop3->Open())=="")
		{
			echo '<a href="log">[back to log]</a><hr />';
			echo "<PRE>Connected to the POP3 server &quot;".$pop3->hostname."&quot;.</PRE>\n";
			if(($error=$pop3->Login($user,$password,$apop))=="")
			{
				echo "<PRE>User &quot;$user&quot; logged in.</PRE>\n";
				if(($error=$pop3->Statistics($messages,$size))=="")
				{
					echo "<PRE>There are $messages messages in the mail box with a total of $size bytes.</PRE>\n";
					$result=$pop3->ListMessages("",0);
					if(GetType($result)=="array")
					{
						for(Reset($result),$message=0;$message<count($result);Next($result),$message++)
							echo "<PRE>Message ",Key($result)," - ",$result[Key($result)]," bytes.</PRE>\n";
						$result=$pop3->ListMessages("",1);
						if(GetType($result)=="array")
						{
							for(Reset($result),$message=0;$message<count($result);Next($result),$message++) {
								
								$uniqueID = $result[Key($result)];
								$entry = DataObject::get_one("Pop3Message_Ignore","UniqueID = '$uniqueID'");
								if ($entry) {
									echo "<PRE>Message ",Key($result),", Unique ID - \"",$result[Key($result)],"\" [part of ignore list]</PRE>\n";
								} else {
									echo "<PRE>Message ",Key($result),", Unique ID - \"",$result[Key($result)],"\" <a href=\"ignore/" . $result[Key($result)] . "\">[ignore]</a></PRE>\n";
								}					
							}
								
							if($messages>0)
							{
								if(($error=$pop3->RetrieveMessage(1,$headers,$body,2))=="")
								{
									/*
									echo "<PRE>Message 1:\n---Message headers starts below---</PRE>\n";
									for($line=0;$line<count($headers);$line++)
										echo "<PRE>",HtmlSpecialChars($headers[$line]),"</PRE>\n";
									echo "<PRE>---Message headers ends above---\n---Message body starts below---</PRE>\n";
									for($line=0;$line<count($body);$line++)
										echo "<PRE>",HtmlSpecialChars($body[$line]),"</PRE>\n";
									echo "<PRE>---Message body ends above---</PRE>\n";
									*/
									
									//message deletion commented out
									/*
									if(($error=$pop3->DeleteMessage(1))=="")
									{
										echo "<PRE>Marked message 1 for deletion.</PRE>\n";
										if(($error=$pop3->ResetDeletedMessages())=="")
										{
											echo "<PRE>Resetted the list of messages to be deleted.</PRE>\n";
										}
									}
									*/
								}
							}
							if($error==""
							&& ($error=$pop3->Close())=="")
								echo "<PRE>Disconnected from the POP3 server &quot;".$pop3->hostname."&quot;.</PRE>\n";
							
						}
						else
							$error=$result;
					}
					else
						$error=$result;
				}
			}
		}
		if($error!="")
			echo "<H2>Error: ",HtmlSpecialChars($error),"</H2>";		
	}
	
	function ignore(){
		$uniqueID = Director::urlParam("ID");
		
		echo "Unique ID is $uniqueID <br />";
		
		$entry = DataObject::get_one("Pop3Message_Ignore","UniqueID = '$uniqueID'");
		if ($entry) {
			echo "ignore entry exists";
		} else {
			$entry = new Pop3Message_Ignore();
			$entry->UniqueID = $uniqueID;
			$entry->write();
			echo "added to ignore list";
		}
		
		echo "<br />";
		
		
		echo "<br />";
		echo "<a href=\"/" . Director::urlParam("Controller") . "/messagelist\">[back to queue]</a>";
	}
	
	
	
	
	//Parsing a message with Manuel Lemos' PHP POP3 and MIME Parser classes
	function getmail(){

		//logmode check
		if (Permission::check("ADMIN") && isset($_GET["logmode"])) {
			$logmode = true;
		} else {
			$logmode = false;
		}
		//debugmode check		
		if (Permission::check("ADMIN") && isset($_GET["debugmode"])) {
			$logmode = true;
			$debugmode = true;
			if ($_GET["debugmode"] == "full") {
				$debugmode = "full";
			}
		} else {
			$debugmode = false;
		}		
		

		$forcerun = false;
		if (Permission::check("ADMIN") && isset($_GET["forcerun"])) {
			$forcerun = true;
		}	
		
		
		//very first thing: chek if last run was successfull
		if (!$debugmode && !$forcerun) {
			$lastLog = DataObject::get_one("Pop3Message_ConnectionLog",NULL,NULL,"ID DESC");
			if ($lastLog) {
				//echo $lastLog->LastEdited;
				if ($lastLog->Success == 0) {
					echo "Last run was unsuccessful. <br />Procedure aborted.<br />Administrator has been notified.";
			
					$from = "TITLEDK Pop3Message Class <" . Email::getAdminEmail(). ">";
					$to = "TITLEDK <" . Pop3Message::$username . ">";
					$subject = "Email stuck";
					$body = "An email seems to be stuck. <br />		
					No further emails will be processed until this problem has been solved. <br />
					Please enter the <a href=\"" . Director::protocolAndHost() . "/" . Director::urlParam("Controller") . "/messagelist\">queue</a>
					or <a href=\"" . Director::protocolAndHost() . "/" . Director::urlParam("Controller") . "/getmail?debugmode\">debug mode</a>
					and solve the problem by setting the appropriate ignore options. <br />
					Make sure to log in as an administrator to perform these actions.
					
					<br />
					<br />
					Pop3Message
					";
						
					$email = new Email($from, $to, $subject, $body);
					$email->send();
					//$email->sendPlain();		
					
					
					
					return false;
				}
			}
		}
		
		
		//as this is called via Cron, we also check for (and send)
		//pending Notifications
		MemberEventDo_Notification::send_pending_notifications();
		
		
		$connlog = new Pop3Message_ConnectionLog();
		$connlog->write();

		
		$base = Director::baseFolder() . "/libs/code/pop3_mimeparser/";
		require_once($base . 'mime_parser.php');
		require_once($base . 'rfc822_addresses.php');
		require_once($base . "pop3.php");
	
		stream_wrapper_register('pop3', 'pop3_stream');  /* Register the pop3 stream handler class */
	
		if ($logmode) echo "<h1>Downloading New Messages (log/debug mode)</h1>";
		if ($logmode) echo "<a href=\"/" . Director::urlParam("Controller") . "/log\">[log]</a><br />";
		$pop3=new pop3_class;
		$pop3->hostname= Pop3Message::$hostname;             /* POP 3 server host name                      */
		$pop3->port= Pop3Message::$port;                         /* POP 3 server host port,
		                                            usually 110 but some servers use other ports
		                                            Gmail uses 995                              */
		$pop3->tls= Pop3Message::$tls;                            /* Establish secure connections using TLS      */
		$user= Pop3Message::$username;                        /* Authentication user name                    */
		$password= Pop3Message::$password;                    /* Authentication password                     */
		$pop3->realm="";                         /* Authentication realm or domain              */
		$pop3->workstation="";                   /* Workstation for NTLM authentication         */
		$apop=0;                                 /* Use APOP authentication                     */
		$pop3->authentication_mechanism="USER";  /* SASL authentication mechanism               */
		$pop3->debug=0;                          /* Output debug information                    */
		$pop3->html_debug=1;                     /* Debug information is in HTML                */
		$pop3->join_continuation_header_lines=1; /* Concatenate headers split in multiple lines */

		if ($debugmode === "full") {
			echo "Debug mode: $debugmode";
			$pop3->debug=1; 
		}
		
		if(($error=$pop3->Open())=="")
		{
			if ($logmode) echo "<PRE>Connected to the POP3 server &quot;".$pop3->hostname."&quot;.</PRE>\n";
			if(($error=$pop3->Login($user,$password,$apop))=="")
			{
				if ($logmode) echo "<PRE>User &quot;$user&quot; logged in.</PRE>\n";
				if(($error=$pop3->Statistics($messages,$size))=="")
				{
					if ($logmode) echo "<PRE>There are $messages messages in the mail box with a total of $size bytes.</PRE>\n";
					

					//finding Unique IDs
					$uniqueIDarr = array();
					$msgProcessArr = array();
					
					$result=$pop3->ListMessages("",1);	
					//echo "test";
					for(Reset($result),$msg=0;$msg<count($result);Next($result),$msg++) {
						//echo "<PRE>Message ",Key($result),", Unique ID - \"",$result[Key($result)],"\"</PRE>\n";
						$uniqueIDarr[(int) Key($result)] = $result[Key($result)]; 					
					}
					/*
					echo "<pre>";
					var_dump($uniqueIDarr);
					echo "</pre>";
					*/
					//echo "test";
					
					if ($logmode) echo "The following messages are ignored:";
					if ($logmode) echo "<ul> \n";
					foreach ($uniqueIDarr as $no => $uniqueID) {
						$entry = DataObject::get_one("Pop3Message_Ignore","UniqueID = '$uniqueID'");
						if ($entry) {
							if ($logmode) {
								echo "<li> \n";
								echo "$no: $uniqueID";
								echo "</li> \n";
							}
						} else {
							$msgProcessArr[] = $no;
						}
					}
					if ($logmode) echo "</ul> \n";

					/*
					echo "<pre>";
					var_dump($msgProcessArr);
					echo "</pre>";
					*/
					
					
					$alreadyImported = false;
					if($messages>0) {
						//for ( $msgNo = 1; $msgNo <= $messages; $msgNo++) {
						//only process 1 message at the time (in order not to get any memory overflows)
						//for ( $msgNo = 1; $msgNo <= 2; $msgNo++) {
						for ( $msgNo = 1; $msgNo <= $messages; $msgNo++) {
							$doImport = false;
							foreach ($msgProcessArr as $no) {
								if ($no == $msgNo) {
									$doImport = true;
								}
							}
							
							/*
							$doImport = false;
							echo "Unique ID " . $uniqueIDarr[$msgNo] . "<br />";
							echo "importing has been turned off as Anselm is working on it (15th September 2010)<br />";
							*/
							
							if ($doImport && !$alreadyImported) {
								$alreadyImported = true;
								//echo "importing message with Unique ID " . $uniqueIDarr[$msgNo] . "<br />";
								
								/*
								//while debugging:
								if ($msgNo > 3) {
									echo "EOF (debugging)";
									return NULL;
								}
								*/
								
								$recipient = NULL;
								$sender = NULL;
								
								if ($logmode) echo "<h2>Processing Message No. $msgNo</h2><hr />";
								if ($logmode) echo "<em>Unique ID " . $uniqueIDarr[$msgNo] . ".</em><br /><br />";
								if ($logmode) echo "If importing this message causes problems, you can <a href=\"ignore/" . $uniqueIDarr[$msgNo] . "\">[ignore it]</a> for next time. <br />";

								
								
								
								//initializing message
								$obj = new Pop3Message();
								$obj->PopHostname = Pop3Message::$hostname;
								$obj->PopUsername = Pop3Message::$username;
								
								//for the case that the script fails somewhere
								//message is saved for better bug tracking
								$obj->write();
								$msglog = new Pop3Message_Log();
								$msglog->MessageID = $obj->ID;
								$msglog->write();
								$obj->LogID = $msglog->ID;
								
								
								$connlog->Logs()->add($msglog);
								
								
								$pop3->GetConnectionName($connection_name);
								$message=$msgNo;
								$message_file='pop3://'.$connection_name.'/'.$message;
								$mime=new mime_parser_class;
								
								/*
								* Set to 0 for not decoding the message bodies
								*/
								$mime->decode_bodies = 1;
								
								$parameters=array(
									'File'=>$message_file,
			
									/* Read a message from a string instead of a file */
									/* 'Data'=>'My message data string',              */
			
									/* Save the message body parts to a directory     */
									/* 'SaveBody'=>'/tmp',                            */
			
									/* Do not retrieve or save message body parts     */
										//'SkipBody'=>1,
								);
								$success=$mime->Decode($parameters, $decoded);
								$pop3->RetrieveMessage($msgNo,$rawHeaders,$rawBody,-1);
								$rawStr = "";
								for($line=0;$line<count($rawHeaders);$line++) {
									$rawStr .= HtmlSpecialChars($rawHeaders[$line]) . "\n";
								}
								$obj->RawHeaders = $rawStr;
								
								
								//saving RAW body is disabled due to performance issues
								/*
								$rawStr = "";
								for($line=0;$line<count($rawBody);$line++) {
									$rawStr .= HtmlSpecialChars($rawBody[$line]) . "\n";
								}														
								$obj->RawBody = $rawStr;
								*/
			
								if(!$success) {
									if ($logmode) {
										echo '<h3>MIME message decoding error: '.HtmlSpecialChars($mime->error)."</h3>\n"; 
									}
									
								} 	else {
									if ($logmode) echo '<h3>MIME message decoding successful</h3>'."\n";
									if ($debugmode == "full") {
										/*
										echo '<h3>Message structure</h3>'."\n";
										echo '<pre>';
											var_dump($decoded[0]);
										echo '</pre>';
										*/
									} 
							
									
									if($mime->Analyze($decoded[0], $results))
									{
										if ($debugmode) {
											echo '<h3>Message analysis</h3>'."\n";
											echo '<pre>';
											var_dump($results);
											echo '</pre>';
										}
										$msgSubject = $results["Subject"];
										if (!Pop3Message::is_utf8($msgSubject)) {
											$msgBody = iconv("ISO-8859-1", "UTF-8", $msgSubject );
										}										
										$obj->Subject = $msgSubject;
										
										$obj->Date = $results["Date"];
										$obj->From_address = $results["From"][0]["address"];
										if (isset($results["From"][0]["name"])) {
											$obj->From_name = $results["From"][0]["name"];
										}
										$obj->To_address = $results["To"][0]["address"];
										if (isset($results["To"][0]["name"])) {
											$obj->To_name = $results["To"][0]["name"];
										}
										if (isset($results["Type"])) {
											$obj->Type = $results["Type"];
										}
										if (isset($results["SubType"])) {
											$obj->Type = $results["SubType"];
										}
										
										//when the messge type is image, file, etc
										//this variable contains file data.
										//this data is handled later, and added to attachments
										//hence, msgBody is kept blank for this case
										if ($results["Type"] == "text" || $results["Type"] == "html") {
											$msgBody = $results["Data"];
											if (!Pop3Message::is_utf8($msgBody)) {
												$msgBody = iconv("ISO-8859-1", "UTF-8", $msgBody );
											}											
											$obj->MessageBody = $msgBody;
										}
										
										
										$obj->write();
										
										if (isset($results["Related"])) {
											$attachments = $results["Related"];
										}
										if (isset($results["Attachments"])) {
											$attachments = $results["Attachments"];
										}
	
										
										if ($logmode) echo "<h3>Analyzing message for user specific data</h3>";									
										
										//1. Trying to identify original message through the 
										//unique ID in subject
										if ($logmode) echo "<h3>Trying to identify original message through the unique ID in subject</h3>";
										$attributes = DataObject::get("Message_MemberAttribute");
										foreach ($attributes as $attr) {
										$pos = strrpos($obj->Subject, $attr->UniqueID);
											if ($pos === false) { // note: three equal signs
											    // not found...
											} else {
												//found!
												$origAttr = $attr;
												$sender = $origAttr->Member();
												if ($logmode) echo "Original sender attribute with ID " . $origAttr->UniqueID . " has been identified! <br />";
												if ($logmode) echo "Sender is " . $sender->FirstName . " " . $sender->Surname . "<br />";
												
												$origMsg = $origAttr->Message();
												$origMsgID = $origMsg->ID;
												
												$recipient = $origMsg->Sender();
												if ($logmode) echo "Recipient is <strong>" . $recipient->FirstName . " " . $recipient->Surname . "</strong><br />";
												
											}
										}
										
										//2. Trying to identify original message through 
										//names
										//TODO to take account for specific email addresses								
										if (!$recipient) {
											if ($logmode) echo "Recipient hasn't been identified.";
											if ($logmode) echo "<h3>Now checking database for matching users</h3>";
											
											$adminMail = Pop3Message::$username;
											if (isset(Pop3Message::$maildomain)) {
												$domain = Pop3Message::$maildomain;
											} else {
												$ar=split("@",$adminMail);
												$domain = $ar[1];
											}
											if ($logmode) echo "Domain: $domain <br />";
											//$testStr = str_replace($domain,$obj->To_address,"");
											$testStr = str_replace("@" . $domain,"",$obj->To_address);
											$testStr = str_replace(".","",$testStr);
											
											if ($logmode) echo "To address is " . $obj->To_address . "<br />";
											
											if ($logmode) echo "Testing string $testStr:<br />";
											$members = DataObject::get("Member");
											foreach ($members as $member) {
												if ($member->UniqueIdentifier() == $testStr) {
													$recipient = $member;
													if ($logmode) echo "Recipient has been identified!<br />";
													if ($logmode) echo "Recipient is <strong>" . $recipient->FirstName . " " . $recipient->Surname . "</strong><br />";
												}
												if ($member->Email == $obj->From_address) {
													$sender = $member;
													if ($logmode) echo "Sender has been identified!<br />";
													if ($logmode) echo "Sender is <strong>" . $sender->FirstName . " " . $sender->Surname . "</strong><br />";
												}
												
											}	
	
											//add sender, in case sender hasn't been identified
											if (!$sender) {
												$sender = new Member();
												$sender->Email = $obj->From_address;
												$ar=split(" ",$obj->From_name);
												$sender->FirstName = $ar[0];
												$sender->Surname = $ar[1];
												//TODO do we need a password?
												
												$sender->write();
												$group = DataObject::get_one('Group', "Code = 'pop3senders'");
												$group->Members()->add($sender);
												
												if ($logmode) echo "Sender has been added to the group Pop3Senders!<br />";
												if ($logmode) echo "Sender is <strong>" . $sender->FirstName . " " . $sender->Surname . "</strong><br />";
												
											}
											
											
											/*
											
											//old code
											$members = DataObject::get("Member");
											
											//finding the recipient
											foreach ($members as $member) {
												$matchname = $member->FirstName . " " . $member->Surname;
												if ($obj->To_name == $matchname) {
													if ($logmode) echo "matching name found: $matchname <br />\n";
													$recipient = $member;
												}
											}
											*/
											
										}
										//adding the first file to attachments (if it exists)
										if (isset($attachments)) {
											if (isset($results['FileName'])) {
												$arr['Type'] = $results['Type'];
												$arr['Data'] = $results['Data'];
												$arr['Description'] = $results['Description'];
												$arr['FileName'] = $results['FileName'];
												$attachments[] = $arr;
											}
										}
										
										
										//attachments are only saved if recipient can be identified
										if ($recipient && isset($attachments)) {
											if ($logmode) echo '<h3>Message contains attachments</h3>'."\n";
											if ($logmode) echo 'processing attachments<br />';
											foreach ($attachments as $a) {
												$aObj = new Pop3Message_Attachment();
												if ($logmode) echo $a["Type"] . ": ";
												
												$aObj->Type = $a["Type"];
												if (isset($a["SubType"])) {
													$aObj->SubType = $a["SubType"];
												}
												$aObj->Description = $a["Description"];
												
												if ($a["Type"] == "text" || $a["Type"] == "html") {
													$msgBody2 = $a["Data"];
													if (!Pop3Message::is_utf8($msgBody2)) {
														$msgBody2 = iconv("ISO-8859-1", "UTF-8", $msgBody2 );
													}											
													$aObj->Data = $msgBody2;												
												} else {
													$tempFileName = $a["FileName"];
													if (!Pop3Message::is_utf8($tempFileName)) {
														$tempFileName = iconv("ISO-8859-1", "UTF-8", $tempFileName );
													}										
													
													
													if ($logmode) echo "processing $tempFileName<br />";
													if (strlen($tempFileName) < 2) {
														$tempFileName = "tempfile";
													}
													$filepath = "assets/pop3msgtemp/" . $tempFileName;
													$folder = Folder::findOrMake('pop3msgtemp');
													
													file_put_contents(Director::baseFolder() . "/" . $filepath,$a["Data"]);
													$file = new File();
													$file->Filename = $filepath;
													//$file->Name = $a["FileName"];
													//$file->setName($tempFileName);
													$file->write();
													$file->setName($tempFileName . "-temp");
													
													if ($sender) {
														$catName = "fra " . $sender->FirstName . " " . $sender->Surname;
													} else {
														if (isset($results["From"][0]["name"])) {
															$catName = "fra " . $results["From"][0]["name"];
														} else {
															$catName = "fra " . $results["From"][0]["address"];
														}													
													}
													$category = DataObject::get_one("PboksCategory", "MemberID = " . $recipient->ID . " AND Name = '$catName'");
													if (!$category) {
														$category = new	PboksCategory();
														$category->Name = $catName;
														$category->MemberID = $recipient->ID;
														$category->write();												
													}
													
													//relocation
													$newfolder = PboksPage::get_folder_by_memberid($recipient->ID);
													$file->setParentID($newfolder->ID);
													//in order to rename the file if duplicates exist
													$file->setName($tempFileName);
													$file->PboksCategoryID = $category->ID;
													if ($file->appCategory() == "image") {
														$file->ClassName = "Image";
													}
													
													$file->write();
													//$file->resetFilename();
													$aObj->FileID = $file->ID;												
													
													
													//$folder->syncChildren();
												}
												if (isset($a["FileName"])) {
													$aObj->FileName = $a["FileName"];
												}
												if (isset($a["FileDisposition"])) {
													$aObj->FileDisposition = $a["FileDisposition"];
												}
												if (isset($a["Encoding"])) {
													$aObj->Encoding = $a["Encoding"];
												}
												$aObj->MessageID = $obj->ID;
												$aObj->write();																																			 
											}
										}									
										
										if ($recipient) {
											if ($logmode) echo "<br /><br />now sending message via TITLEDK message system... <br /><br />";
											$message = new Message();
											$message->Content = $obj->MessageBody;
											//$message->Content = $obj->RawBody;
											
											if ($sender) {
												$message->SenderID = $sender->ID;
											}
											
											if (isset($origMsgID)) {
												$message->AnswerToID = $origMsgID;
											}
											
											$message->Pop3MessageID = $obj->ID;
											
											$message->write();
											$obj->InternalMessageID = $message->ID;
											$obj->write();		
																			
											$recipients = $message->Recipients();
											$recipients->add($recipient);
											$message->sendNotification($recipient,$sender);
											
										
											//deleting message in the end
											$deleteMsg = $pop3->DeleteMessage($msgNo);
											if ($logmode) echo $deleteMsg;
											
											$msglog->Success = true;
											$msglog->write();

											
											$emailSenderName = "xxxxxx";
											if (isset($catName)) {
												$emailSenderName = $catName;	
											}
									
											//email
											$from = "TITLEDK Pop3Message Class <" . Email::getAdminEmail(). ">";
											$to = "TITLEDK <" . Pop3Message::$username . ">";
											$subject = "A message has been successfuly processed ($emailSenderName)";
											$body = "A message has been successfuly processed ($emailSenderName). <br />		
											Please see the <a href=\"" . Director::protocolAndHost() . "/" . Director::urlParam("Controller") . "/log\">message log</a> for further information.
											
											<br />
											Make sure to log in as an administrator to perform these actions.
											
											<br />
											<br />
											Pop3Message
											";
												
											$email = new Email($from, $to, $subject, $body);
											$email->send();
											
											
										} else {
											//we save the raw message in the database, and delete it
											
	
											$msglog->ErrorMsg = "Recipient couldn't be identified. No internal message has been processed";
											$msglog->write();										
											
											$rawStr = "";
											for($line=0;$line<count($rawBody);$line++) {
												$rawStr .= HtmlSpecialChars($rawBody[$line]) . "\n";
											}														
											$obj->RawBody = $rawStr;
											$obj->write();
	
											$deleteMsg = $pop3->DeleteMessage($msgNo);
											if ($logmode) echo $deleteMsg;
											
											//email
											$from = "TITLEDK Pop3Message Class <" . Email::getAdminEmail(). ">";
											$to = "TITLEDK <" . Pop3Message::$username . ">";
											$subject = "A message has been processed, but recipient couldn't be identified";
											$body = "A message has been processed, but recipient couldn't be identified. <br />		
											Please see the <a href=\"" . Director::protocolAndHost() . "/" . Director::urlParam("Controller") . "/log\">message log</a> for further information.
											
											<br />
											Make sure to log in as an administrator to perform these actions.
											
											<br />
											<br />
											Pop3Message
											";
												
											$email = new Email($from, $to, $subject, $body);
											$email->send();											
										}
	
									} else {
										/*
										if ($logmode) echo 'MIME message analyse error: '.$mime->error."\n";
										$msglog->ErrorMsg .= 'MIME message analyse error: '.$mime->error."\n";
										$msglog->write();
										*/
									}
								
								}
																
							}

						}							
					}
					if($error==""
					&& ($error=$pop3->Close())=="")
						if ($logmode) echo "<PRE>Disconnected from the POP3 server &quot;".$pop3->hostname."&quot;.</PRE>\n";
				}
			}
		}
		if($error!="")
			if ($logmode) echo "<H3>Error: ",HtmlSpecialChars($error),"</H3>";		
		

/*			
		//while testing, send new email:
		if ($obj) {
			$no = $obj->ID;
		} else {
			$no = "XX";
		}
		//forcing to send email to real recipient
		Email::send_all_emails_to("");		
		
		$from = "TITLEDK Pop3Message Class <" . Email::getAdminEmail(). ">";
		$to = "Dummy <" . Pop3Message::$username . ">";
		$subject = "Test email no. $no";
		$body = "This is test email no. $no.
This email contains some Danish and other non-standard characters for testing:
æøå
én 
ÆØÅ		
		
		";
			
		$email = new Email($from, $to, $subject, $body);
		//$email->send();
		$email->sendPlain();		
*/

		$connlog->Success = 1;
		$connlog->write();			
			
	}

	function is_utf8($string) {
	    
	    // From http://w3.org/International/questions/qa-forms-utf-8.html
	    return preg_match('%^(?:
	          [\x09\x0A\x0D\x20-\x7E]            # ASCII
	        | [\xC2-\xDF][\x80-\xBF]             # non-overlong 2-byte
	        |  \xE0[\xA0-\xBF][\x80-\xBF]        # excluding overlongs
	        | [\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}  # straight 3-byte
	        |  \xED[\x80-\x9F][\x80-\xBF]        # excluding surrogates
	        |  \xF0[\x90-\xBF][\x80-\xBF]{2}     # planes 1-3
	        | [\xF1-\xF3][\x80-\xBF]{3}          # planes 4-15
	        |  \xF4[\x80-\x8F][\x80-\xBF]{2}     # plane 16
	    )*$%xs', $string);
	    
	} // function is_utf8	
	
	
	
}

class Pop3Message_Attachment extends DataObject {

	static $db = array(
		'Type' => 'Varchar',
		'SubType' => 'Varchar',	
		'Description' => 'Varchar',
		'Data' => 'Text',
		'FileName' => 'Varchar',
		'FileDisposition' => 'Varchar',
		'Encoding' => 'Varchar',		
	);

	static $has_one = array(
		'Message' => 'Pop3Message',
		'File' => 'File'
	);

	function getContent() {
		$obj = $this->dbObject('Data');
		
		return $obj->XML();
	}
	
	
}

class Pop3Message_ConnectionLog extends DataObject {

	static $db = array(
		'Success' => 'Boolean',
		'LogMsg' => 'Text',
		'ErrorMsg' => 'Text',
	);

	static $has_many = array(
		'Logs' => 'Pop3Message_Log',
	);

	function onAfterWrite() {
		if (!isset($this->Sucess)) {
			$this->Sucess = false;
			$this->write();
		}
	}	


}

class Pop3Message_Ignore extends DataObject {

	static $db = array(
		'UniqueID' => 'Varchar(255)'
	);

}



class Pop3Message_Log extends DataObject {

	static $db = array(
		'Success' => 'Boolean',
		'LogMsg' => 'Text',
		'ErrorMsg' => 'Text',
	);

	static $has_one = array(
		'ConnectionLog' => 'Pop3Message_ConnectionLog',
		'Message' => 'Pop3Message',
	);

	function onAfterWrite() {
		if (!isset($this->Sucess)) {
			$this->Sucess = false;
			$this->write();
		}
	}	


}


class Pop3Message_Controller extends Controller {

	function init(){
		if (isset($_GET["all"])) {
			$this->Limit = NULL;
		}
		parent::init();
	}
	
	public $Limit = 100;
	
	function getmail(){
		Pop3Message::getmail();
		//echo Director::baseFolder();
	}

	function ignore(){
		if (Permission::check("ADMIN")) {
			Pop3Message::ignore();
		} else {
			echo "permission denied";
		}
	}		
	
	function messagelist(){
		if (Permission::check("ADMIN")) {
			Pop3Message::messagelist();
		} else {
			echo "permission denied";
		}
	}	
	
	
	function log(){
		if (Permission::check("ADMIN")) {
			return $this->renderWith("Pop3Message_log");		
		} else {
			echo "permission denied";
		}
	}

	function recipientlist(){
		if (Permission::check("ADMIN")) {
			$group = DataObject::get_one("Group","code ='patients'");
			$members = $group->Members(NULL,NULL,NULL,"FirstName ASC");			
			
			$html = '<a href="/' . $this->Controller() . '/log">[log]</a><br />';
			foreach ($members as $member) {
				$email = $member->UniqueIdentifier . '@' . Pop3Message::$maildomain;
				$html .= $member->FirstName . ' ' . $member->Surname . ': <a href="mailto:' . $email . '">' . $email . '</a><br />'. "\n";
			}
			
			
			return $html;
		
		} else {
			echo "permission denied";
		}		
	}
	
	function ConnectionLogs(){
		$where = "";
		if (isset($_GET['nosuccess'])) {
			$where .= "Success = 0";
		}
		$join = "";
		if (isset($_GET['messages'])) {
			$join = "
				INNER JOIN Pop3Message_Log ON Pop3Message_ConnectionLog.ID = Pop3Message_Log.ConnectionLogID
			";
		}
		
		$limit = $this->Limit;
		$dos = DataObject::get("Pop3Message_ConnectionLog",$where,"ID DESC", $join, $limit);
		return $dos;
	}
	
	function Controller(){
		return Director::urlParam("Controller");		
	}		
	
}