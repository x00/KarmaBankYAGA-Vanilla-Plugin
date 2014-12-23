<?php  if (!defined('APPLICATION')) exit();
$PluginInfo['KarmaBankYAGA'] = array(
    'Name' => 'KarmaBank YAGA',
    'Description' => 'Extends KarmaBank to set rules that award when badges and reactions are given, also can also set rules when reactions are taken away.',
    'RequiredApplications' => array('Vanilla' => '2.1'),
    'RequiredPlugins' => array('KarmaBank' => '0.9.6.9b'),
    'Version' => '0.1.2b',
    'Author' => "Paul Thomas",
    'AuthorEmail' => 'dt01pqt_pt@yahoo.com'
);

class KarmaBankYAGA extends Gdn_Plugin {
    
    protected $AwardTypes = array('Badges', 'Reactions');
    protected $AwardDirections = array(
        'Awarded' => 'Awarded',
        'UnAwarded' => 'UnAwarded',
        'Awards' => 'Awards',
        'UnAwards' => 'UnAwards'
    );
    protected $AwardModels = array(
        'Badges'=> array(
            'Model'=>'BadgeModel',
            'ID' => 'BadgeID',
            'Name' => 'Name',
            'Directions' => array('Awarded'),
            'DirectionsPrefix' => array('Awarded' => 'that are '),
            'All' => 'Get'
        ), 
        'Reactions'=> array(
            'Model'=>'ActionModel',
            'ID' => 'ActionID',
            'Name' => 'Name',
            'Directions' => array('Awarded', 'UnAwarded', 'Awards', 'UnAwards'),
            'DirectionsPrefix' => array('Awarded' => 'that are ', 'UnAwarded' => 'that are ', 'Awards' => 'a user ' , 'UnAwards'=> 'a user '),
            'All' => 'Get'
        )
    );
    protected $ActivityRecordTypes = array('Badge');
    protected $NewActivity = array();
    
    function __construct(){
        parent::__construct();
        $this->FireEvent('Init');
        foreach($this->ActivityRecordTypes As $RecordTypes){
            $this->NewActivity[$RecordTypes] = NULL;
        }
    }
    
    public function ReactionModel_AfterReactionSave_Handler($Sender, $Args) {
        $ActionModel = Yaga::ActionModel();
        $Action = $ActionModel->GetByID(GetValue('ActionID',$Args));
        if(!$Action)
            return;
        $Slug = str_replace(' ', '', ucwords(str_replace('-', ' ',Gdn_Format::Url($Action->Name))));
        //recieving
        if(GetValue('Exists',$Args)){ // award
            $AwardDirection = "Awarded";
        }else{ // unaward
            $AwardDirection = "UnAwarded";
        }

        $UserID = GetValue('ParentUserID',$Args);
        $this->UpdateCount($UserID, 'Reactions', "Reactions.{$Slug}_{$Action->ActionID}.{$AwardDirection}", "Reactions.Default.{$AwardDirection}");
        
        //giving
        if(GetValue('Exists',$Args)){ // award
            $AwardDirection = "Awards";
        }else{ // unaward
            $AwardDirection = "UnAwards";
        }
        
        $UserID = GetValue('InsertUserID',$Args);
        $this->UpdateCount($UserID, 'Reactions', "Reactions.{$Slug}_{$Action->ActionID}.{$AwardDirection}", "Reactions.Default.{$AwardDirection}");
    }
    
    public function BadgeAwardModel_AfterBadgeAward_Handler($Sender) {
        if($this->NewActivity['Badge']){
            $Data = is_string(GetValue('Data',$this->NewActivity['Badge'])) ? Gdn_Format::Unserialize($this->NewActivity['Badge']['Data']) : $this->NewActivity['Badge']['Data'];
            $BadgeName = GetValue('Name',$Data);
            $BadgeID = GetValue('RecordID',$this->NewActivity['Badge']);
            $AwardMetaCounts = array();
            if($BadgeName && $BadgeID){
                $Slug = str_replace(' ', '', ucwords(str_replace('-', ' ',Gdn_Format::Url($BadgeName))));
                //recieving
                $UserID = GetValue('ActivityUserID', $this->NewActivity['Badge']);
                $this->UpdateCount($UserID, 'Badges',"Badges.{$Slug}_{$BadgeID}.Awarded", "Badges.Default.Awarded");
            }
            
            $this->NewActivity['Badge'] = NULL;
        }
    }
    
    public function ActivityModel_BeforeSave_Handler($Sender, $Args){
        $ActivityID = GetValueR('ActivityID', $Args);
        $RecordType = GetValueR('Activity.RecordType', $Args);
        if (!$ActivityID && in_array($RecordType, $this->ActivityRecordTypes)) {
            $this->NewActivity[$RecordType] = GetValue('Activity', $Args);
        }
    }

    
    public function KarmaBank_KarmaBankMetaMap_Handler($Sender, $Args){
        $Defaults = array();
        foreach($this->AwardTypes As $AwardType){
            if(GetValue($AwardType, $this->AwardModels)){
                $Model = GetValueR("{$AwardType}.Model", $this->AwardModels);
                
                if(!$Model)
                    continue;
                $ModelObject = new $Model();
                
                if(!$ModelObject)
                    continue;
                
                $All = GetValueR("{$AwardType}.All", $this->AwardModels, 'Get');
                
                $Awards = $ModelObject->$All();
                
                if(!$Awards)
                    continue;
                    
                foreach($Awards As $Award){
                    $ID = GetValueR("{$AwardType}.ID",$this->AwardModels,"{$AwardType}ID");
                    $Name = GetValueR("{$AwardType}.Name",$this->AwardModels,"Name");
                    $AwardName = $Award->$Name;
                    $AwardID = $Award->$ID;
                    $Slug = str_replace(' ', '', ucwords(str_replace('-', ' ',Gdn_Format::Url($AwardName))));
                    $Directions = GetValueR("{$AwardType}.Directions", $this->AwardModels, array('Awarded'));
                    foreach($Directions As $Direction){
                        $Direction = GetValue($Direction , $this->AwardDirections,'Adwarded');
                        $DirectionsPrefix = GetValueR("{$AwardType}.DirectionsPrefix.{$Direction}", $this->AwardModels, '');
                        $Sender->AddMeta(
                            "YAGA.{$AwardType}.{$Slug}_{$AwardID}.{$Direction}",
                            "Counts \"{$AwardName}\" YAGA {$AwardType} {$DirectionsPrefix}{$Direction}"
                        );
                        if(!GetValue("YAGA.{$AwardType}.Default.{$Direction}",$Defaults)){
                            $Defaults["YAGA.{$AwardType}.Default.{$Direction}"] = "Counts Default YAGA {$AwardType} {$DirectionsPrefix}{$Direction}";
                        }
                    }
                    
                }
            }
        }
        
        foreach($Defaults As $Condition => $Description){
            $Sender->AddMeta(
                $Condition,
                $Description
            );
        }
    }
    
    protected function UpdateCount($UserID, $AwardType, $AwardMeta, $DefaultMeta){
        $Counts = Gdn::UserModel()->GetMeta($UserID, "YAGA.{$AwardType}.%");
        if(!$Counts){
            $Counts = array();
        }
        $KarmaRules = new KarmaRulesModel();
        $Rules = $KarmaRules->GetRules();
        $HasRule = FALSE;
        foreach($Rules As $Rule){
            if($Rule->Condition == "YAGA.{$AwardMeta}"){
                if(GetValue("YAGA.{$AwardMeta}",$Counts)){
                    $Counts["YAGA.{$AwardMeta}"]=intval($Counts["YAGA.{$AwardMeta}"])+1;
                }else{
                    $Counts["YAGA.{$AwardMeta}"]=1;
                }
                $HasRule = TRUE;
                break;
            }
        }
        if(!$HasRule){
            foreach($Rules As $Rule){
                if($Rule->Condition == "YAGA.{$DefaultMeta}"){
                    if(GetValue("YAGA.{$DefaultMeta}",$Counts)){
                        $Counts["YAGA.{$DefaultMeta}"]=intval($Counts["YAGA.{$DefaultMeta}"])+1;
                    }else{
                        $Counts["YAGA.{$DefaultMeta}"]=1;
                    }
                    break;
                }
            }
        }
        Gdn::UserModel()->SetMeta($UserID, $Counts);
    }
    
    public function Base_BeforeControllerMethod_Handler($Sender) {
        if(!Gdn::PluginManager()->GetPluginInstance('KarmaBank')->IsEnabled())
          return;
        if(!Gdn::Session()->isValid()) return;
         
    }
    
}
