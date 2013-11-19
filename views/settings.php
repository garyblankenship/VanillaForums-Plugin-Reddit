<?php if (!defined('APPLICATION')) exit();
?>
<style type="text/css">
.Configuration {
   margin: 0 20px 20px;
   background: #f5f5f5;
   float: left;
}
.ConfigurationForm {
   padding: 20px;
   float: left;
}
#Content form .ConfigurationForm ul {
   padding: 0;
}
#Content form .ConfigurationForm input.Button {
   margin: 0;
}
.ConfigurationHelp {
   border-left: 1px solid #aaa;
   margin-left: 340px;
   padding: 20px;
}
.ConfigurationHelp img {
   width: 99%;
}
.ConfigurationHelp a img {
    border: 1px solid #aaa;
}
.ConfigurationHelp a:hover img {
    border: 1px solid #777;
}
.ConfigurationHelp ol {
    list-style-type: decimal;
    padding-left: 20px;
}
input.CopyInput {
   font-family: monospace;
   color: #000;
   width: 240px;
   font-size: 12px;
   padding: 4px 3px;
}
</style>
<h1><?php echo $this->Data('Title'); ?></h1>
<?php
echo $this->Form->Open();
echo $this->Form->Errors();
?>
<div class="Info">
   <?php echo T('Reddit Connect allows users to sign in using their Reddit account.', 'Reddit Connect allows users to sign in using their Reddit account. <b>You must register your application with Reddit for this plugin to work.</b>'); ?>
</div>
<div class="Configuration">
   <div class="ConfigurationForm">
      <ul>
         <li>
            <?php
               echo $this->Form->Label('Client ID', 'ClientID');
               echo $this->Form->TextBox('ClientID');
            ?>
         </li>
         <li>
            <?php
               echo $this->Form->Label('Client Secret', 'Secret');
               echo $this->Form->TextBox('Secret');
            ?>
         </li>
         
		  <li>
            <?php
               echo $this->Form->CheckBox('SocialSignIn', 'Enable Social Sign In');
            ?>
         </li>
		 
      </ul>
      <?php echo $this->Form->Button('Save', array('class' => 'Button SliceSubmit')); ?>
   </div>
   <div class="ConfigurationHelp">
      <p><strong>How to set up Reddit Connect</strong></p>
      <ol>
         <li>You must register your Vanilla Forum with Reddit at: <a href="https://ssl.reddit.com/prefs/apps">https://ssl.reddit.com/prefs/apps</a></li>
         <li>Set the <strong>Callback URL</strong> by appending &ldquo;/forum/entry/connect/reddit&rdquo; to the end of your forum&rsquo;s URL. 
         (If your forum is at example.com/forum, your Callback URL would be http://example.com/forum/entry/connect/reddit).</li>
         <li>After registering, copy the "Client ID" and "Secret ID" into the form on this page and click Save.</li>
      </ol>
      <p><?php echo Anchor(Img('/plugins/Reddit/design/help-consumervalues-sm.png', array('style' => 'max-width: 763px;')), '/plugins/Reddit/design/help-consumervalues-sm.png', array('target' => '_blank')); ?></p>
   </div>
  
</div>   
<?php
   echo $this->Form->Close();
