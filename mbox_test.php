<?PHP

	// some random content
	$content = <<<EOF
From Foo@example.com Fri Dec 27 14:31:10 2002
Return-Path: 
Received: from [unix socket] by campos.terra.com.br (LMTP); Fri, 27 Dec
	2002 14:31:10 -0200 (BRST)
Date: Fri, 27 Dec 2002 14:31:21 -0500
Message-Id: <200212271931.gBRJVL012289@example.com>
Received: from  pcp128525pcs.medfrd01.nj.comcast.net (
	pcp128525pcs.medfrd01.nj.comcast.net [68.45.42.4]) by
	serjolen6com.siteprotect.net (v64.19) with ESMTP id
	MAILRELAYINZA98-3601058302; Fri, 08 Nov 2002 06:39:05 -0500
From: "Foo@example.com"
To: fool@example.com
Subject: This is A SPAM!!
Content-Type: text/plan

testing foo spam
EOF;

	// starting mbox
	require_once "mbox.php";
	$mbox	=&	new Mail_Mbox();

	// uncomment to see lots of things
	#$mbox->debug	= true;

	// opennign file mbox
	$mid 	= 	$mbox->open("mbox");

	// uncomment to see internal vars
	#print_r($mbox);



	// deleting a message (uncomment to test)
	#$res1 =  $mbox->delete($mid,0);
	if (PEAR::isError($res1))
	{
		print $res1->getMessage();
	}





        // changing a message (uncomment to test)
	#$res2 = $mbox->update($mid,0,$content);
        if (PEAR::isError($res2))
        {
                print $res2->getMessage();
        }


        // adding a message (uncomment to test)
        $res3 = $mbox->insert($mid,$content,0);
        if (PEAR::isError($res3))
        {
                print $res3->getMessage();
        }



	require_once "Mail/mimeDecode.php";
	// showing current messages with Mail Mime
	for ($x = 0; $x < $mbox->size($mid); $x++)
	{
		printf("Message: %08d<pre>",$x);
		$thisMessage 	= $mbox->get($mid,$x);
		print $thisMessage;	
		print "<hr>";
		$decode = new Mail_mimeDecode($thisMessage, "\r\n");
		$structure = $decode->decode();
		print_r($structure);

		print "</pre><hr><hr><hr>";
	}

	
?>
