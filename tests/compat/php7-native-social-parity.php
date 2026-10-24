<?php
/** PHP8 comparison against the modern profile, message and friend encoders. */
declare(strict_types=1);
foreach(array('runtime','users','social') as $module){require __DIR__.'/../../compat/php7/'.$module.'.php';}
foreach(array('ProtocolText','GdUserEncoder','GdMessageEncoder','GdRelationshipEncoder') as $module){require __DIR__.'/../../src/Protocol/'.$module.'.php';}
require __DIR__.'/../../src/User/GameRole.php';
date_default_timezone_set('UTC');
$row=array('account_id'=>2,'to_account_id'=>2,'peer_id'=>2,'user_id'=>20,'username'=>'Peer',
    'icon_id'=>5,'icon_type'=>1,'color1'=>3,'color2'=>7,'color3'=>-1,'special'=>1,'glow'=>1,
    'stars'=>25,'demons'=>3,'creator_points'=>2,'secret_coins'=>4,'user_coins'=>9,'diamonds'=>11,'moons'=>6,
    'id'=>8,'comment'=>base64_encode('Hello'),'is_read'=>0,'is_new'=>1,'created_at'=>'2026-10-22 12:00:00');
$relations=new MuchoCore\Protocol\GdRelationshipEncoder();
$expected=explode('#',$relations->requests(array($row),1,0,true))[0];
if(mucho7_relationship_wire($row,true)!==$expected){throw new RuntimeException('Native friend request wire mismatch');}
if(mucho7_relationship_wire($row,false,true)!==$relations->users(array($row),true)){throw new RuntimeException('Native 2.209 friend stats wire mismatch');}
$row['subject']=base64_encode('Subject');$row['body']=base64_encode('Opaque body');
$row['to_username']='Peer';$row['to_user_id']=20;
$messages=new MuchoCore\Protocol\GdMessageEncoder();
if(mucho7_message_wire($row,true,true)!==$messages->encodeMessage($row,true)){throw new RuntimeException('Native private message wire mismatch');}
$profile=array_merge($row,array('role_code'=>'owner','ship'=>17,'ball'=>8,'friend_state'=>3,
    'message_state'=>1,'friend_request_state'=>1,'comment_history_state'=>2,
    'request_id'=>9,'request_comment'=>base64_encode('Friend request'),'request_date'=>'22/10/2026 12.00',
    'messages_count'=>2,'friend_requests_count'=>1,'friends_count'=>3));
$users=new MuchoCore\Protocol\GdUserEncoder();
if(mucho7_user_profile_wire($profile)!==$users->profile($profile,22,51)){throw new RuntimeException('Native extended profile wire mismatch');}
echo "PHP7_NATIVE_SOCIAL_EXTENDED_PROFILE_WIRE_PARITY_OK\n";
