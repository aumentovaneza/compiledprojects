<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Yajra\Datatables\Datatables;
use App\AWSInstance;
use Aws\Laravel\AwsFacade as AWS;
use Aws\Laravel\AwsServiceProvider;
use Aws\Iam\IamClient;
use Aws\Ec2\Ec2Client;
use Aws\Kms\KmsClient; 
use Aws\Exception\AwsException;

/**
 * This controller handles the connection to EC2-AWS where users can create 
 * server instances
 */
class ProfileController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function initialize(){
        $ec2Client = Ec2Client::factory(array(
            'key'    => 'AKIAW7VBAEPLUBUG26GC',
            'secret' => 'EvaOvdK4gBuzGKlnQI7ebMCZyjBg1nNaoBlTDzYg',
            'region' => 'us-east-1', // (e.g., us-east-1)
            'version' => 'latest'
        ));

        return $ec2Client;
    }

    public function createInstance(Request $request)
    {
    	$userId = \Auth::user()->id;
    	$userEmail = \Auth::user()->email;
        $profileType = $request->profile_type;

        $ec2Client = $this->initialize();

        $keyPairName = "NewKeyPairAccstream_".rand();
        $result = $ec2Client->createKeyPair(array(
            'KeyName' => $keyPairName
        ));

        putenv('HOME=/home/vagrant/code/laravel/accstream/public');

        // Save the private key
        $saveKeyLocation = public_path(".ssh/$keyPairName".".pem");
        file_put_contents($saveKeyLocation, $result['KeyMaterial']);

        // Update the key's permissions so it can be used with SSH
        chmod($saveKeyLocation, 0600);
        // Create the security group
        $securityGroupName = 'my-security-group'.time();
        $result = $ec2Client->createSecurityGroup(array(
            'GroupName'   => $securityGroupName,
            'Description' => 'Basic web server security'
        ));

        // Get the security group ID (optional)
        $securityGroupId = $result->get('GroupId');

        // Set ingress rules for the security group
        $ec2Client->authorizeSecurityGroupIngress(array(
            'GroupName'     => $securityGroupName,
            'IpPermissions' => array(
                array(
                    'IpProtocol' => 'tcp',
                    'FromPort'   => 80,
                    'ToPort'     => 80,
                    'IpRanges'   => array(
                        array('CidrIp' => '0.0.0.0/0')
                    ),
                ),
                array(
                    'IpProtocol' => 'tcp',
                    'FromPort'   => 3389,
                    'ToPort'     => 3389,
                    'IpRanges'   => array(
                        array('CidrIp' => '0.0.0.0/0')
                    ),
                ),
                array(
                    'IpProtocol' => 'tcp',
                    'FromPort'   => 22,
                    'ToPort'     => 22,
                    'IpRanges'   => array(
                        array('CidrIp' => '0.0.0.0/0')
                    ),
                )
            )
        ));

        ### this is the image id to use for windows ami-0204606704df03e7e
        ### this is the image id to use for linux ami-0a313d6098716f372

        if($profileType == "Linux"){
            $result = $ec2Client->runInstances(array(
                'ImageId'        => 'ami-0a313d6098716f372',
                'MinCount'       => 1,
                'MaxCount'       => 1,
                'InstanceType'   => 't2.micro',
                'KeyName'        => $keyPairName,
                'SecurityGroups' => array($securityGroupName),
            ));

            $arr = $result->toArray();

            $instanceId = $arr['Instances'][0]['InstanceId'];
 
            $ec2Client->waitUntil('InstanceRunning', ['InstanceIds' => array($instanceId)]);

            $result = $ec2Client->describeInstances(array(
                'InstanceIds' => array($instanceId),
            ));

            $instanceInfo = $result->get('Reservations')[0]['Instances'];
            // Get the Public IP of the instance
            $instancePublicIP = $instanceInfo[0]['PublicIpAddress'];
            // Get the Public DNS of the instance
            $instancePublicDNS = $instanceInfo[0]['PublicDnsName'];
            // Get the Instance ID
            $instanceID = $instanceInfo[0]['InstanceId'];
            //Get keyName
            $instanceKeyName = $instanceInfo[0]['KeyName'];

            // $result = $client->getPasswordData([
            //     'DryRun' => true || false,
            //     'InstanceId' => '<string>', // REQUIRED
            // ]);

            // Get the instance state
            $instanceState = $instanceInfo[0]['State']['Name'];

            if ( $instanceState == "running" || $instanceState == "Running" ) {
                $this->saveForm($userId, $request->profileowner, $request->profile_type, $request->amazon_account,$userEmail, $instancePublicIP,$instanceID, $instanceState,$instanceKeyName);
            } else {
                echo "not running";
            }

        } else if($profileType == "Windows") {
            $result = $ec2Client->runInstances(array(
                'ImageId'        => 'ami-0204606704df03e7e',
                'MinCount'       => 1,
                'MaxCount'       => 1,
                'InstanceType'   => 't2.micro',
                'KeyName'        => $keyPairName,
                'SecurityGroups' => array($securityGroupName),
            ));
            
            $arr = $result->toArray();

            $instanceId = $arr['Instances'][0]['InstanceId'];

        
            
            $ec2Client->waitUntil('InstanceRunning', ['InstanceIds' => array($instanceId)]);

            $result = $ec2Client->describeInstances(array(
                'InstanceIds' => array($instanceId),
            ));

            $instanceInfo = $result->get('Reservations')[0]['Instances'];
            // Get the Public IP of the instance
            $instancePublicIP = $instanceInfo[0]['PublicIpAddress'];
            // Get the Public DNS of the instance
            $instancePublicDNS = $instanceInfo[0]['PublicDnsName'];
            // Get the Instance ID
            $instanceID = $instanceInfo[0]['InstanceId'];
            // Get the instance state
            $instanceState = $instanceInfo[0]['State']['Name'];
            //Get keyName
            $instanceKeyName = $instanceInfo[0]['KeyName'];

            if ( $instanceState == "running" || $instanceState == "Running" ) {
                $this->saveForm($userId, $request->profileowner, $request->profile_type, $request->amazon_account,$userEmail, $instancePublicIP, $instanceID, $instanceState,$instanceKeyName);
            } else {
                echo "not running";
            }
        }

        return response()->json(['success' => 'Data is successfully added']);
    }

    public function saveForm($userID, $profileOwner, $profileType, $amazonAccount, $email, $ipAddress, $instanceID,$instanceState, $instanceKeyName)
    {
        $profile = AWSInstance::create(
            [
                'user_id'           => $userID,
                'profile_owner'     => $profileOwner,
                'profile_type'      => $profileType,
                'amazon_account'    => $amazonAccount,
                'email'             => $email,
                'instance_id'       => $instanceID,
                'ipaddress'         => $ipAddress,
                'state'             => $instanceState,
                'keyname'           => $instanceKeyName,
            ]
        );
    }

    public function startStopInstance($id,$awsinstance,$state)
    {
        $ec2Client = $this->initialize();

        $instanceIds = array($awsinstance);

        if ($state == 'running' || $state == 'Running' ) {
            $result = $ec2Client->stopInstances(array(
                'InstanceIds' => $instanceIds,
            ));

            $ec2Client->waitUntil('InstanceStopped', ['InstanceIds' => $instanceIds]);

        } else {

            $result = $ec2Client->startInstances(array(
            'InstanceIds' => $instanceIds,
            ));

            $ec2Client->waitUntil('InstanceRunning', ['InstanceIds' => $instanceIds]);
        }

        
        $result = $ec2Client->describeInstances(array(
            'InstanceIds' => $instanceIds,
        ));

        $instanceInfo = $result->get('Reservations')[0]['Instances'];
        // Get the instance state
        $instanceState = $instanceInfo[0]['State']['Name'];

        $aws = AWSInstance::find($id);

        $aws->state = $instanceState;
        $aws->save();

        return response()->json(['success' => 'Data is successfully updated']);
    }

    public function terminateInstance($id,$awsinstance,$state)
    {
        $ec2Client = $this->initialize();
        $instanceIds = array($awsinstance);

        $result = $ec2Client->terminateInstances(array(
            // InstanceIds is required
            'InstanceIds' => $instanceIds,
        ));
        $ec2Client->waitUntil('InstanceTerminated', ['InstanceIds' => $instanceIds]);

        $aws = AWSInstance::find($id);

        $aws->state = 'terminated';
        $aws->save();

        return response()->json(['success' => 'Instance is successfully terminated']);
    }

    public function updateStateRealtime()
    {
        $getSavedInstances = AWSInstance::all();

        $ec2Client = $this->initialize();
        $result = $ec2Client->describeInstances();

        $reservations = $result['Reservations'];
        $arr =[];

        foreach ($reservations as $reservation) {
            foreach ($reservation['Instances'] as $res) {
                foreach($getSavedInstances as $instance){
                    if($res['InstanceId'] == $instance->instance_id) {
                        $state = $res['State']['Name'];
                        $id = $instance->id;

                        $updateState = AWSInstance::find($id);
                        $updateState->state = $state;
                        $updateState->save();
                    }
                    
                }

            }

        }

        return response()->json(array("success"=>true));
    }
}
