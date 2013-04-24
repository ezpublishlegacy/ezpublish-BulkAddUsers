<?php

/**
* Allows the bulk addition and removal of
* users to ezPublish
*
* Useful for load testing
*
*
*/

require 'autoload.php';

$cli = eZCLI::instance();
$script = eZScript::instance( array( 'description' => ( "Bulk Add Users\n" .
                                                        "This script allows programmatic bulk adding of users to ezpublish.\n"),
                                     'use-session'    => false,
                                     'use-modules'    => true,
                                     'use-extensions' => true,
                                    )
                             );

$script->startup();
$sys = eZSys::instance();
$script->initialize();

//login as admin
$admin = eZUser::fetchByName("admin");
eZUser::setCurrentlyLoggedInUser($admin, $admin->id());


//$first_name, $last_name, $email, $password
$new = new BulkAddUsers( "NewGuy", "TheGuy", "Guy@guy.com", "password" );
$new->add_the_users();

$script->shutdown( 0, "Done" );


/**
 * @todo Write some proper documentation
 */
class BulkAddUsers
{

    protected $first_name;
    protected $last_name;
    protected $email;
    protected $login;
    protected $password;
    protected $password_hash;
    protected $password_hash_type;

    
    function __construct( $first_name, $last_name, $email, $password ){

        $this->first_name            = $first_name;
        $this->last_name             = $last_name;
        $this->email                 = $email;
        $this->login                 = $this->email;
        $this->password              = $password;
        $this->password_hash_type    = 2;

    }

    /**
     * Add the users
     *
     * Creates all the necessary links
     *
     */
    public function add_the_users(){

        $ini = eZINI::instance();
        $userClassID      = $ini->variable( "UserSettings", "UserClassID" );
        $userCreatorID    = $ini->variable( "UserSettings", "UserCreatorID" );
        $defaultSectionID = $ini->variable( "UserSettings", "DefaultSectionID" );
        $defaultUserPlacement   = $ini->variable( "UserSettings", "DefaultUserPlacement" );

        $class = eZContentClass::fetch( $userClassID );
        $userObject = $class->instantiate( $userCreatorID, $defaultSectionID );

        $userObject->store();

        $userID = $userObjectID = $userObject->attribute( 'id' );

        $version = $userObject->version( 1 );
        $version->setAttribute( 'modified', time() );
        $version->setAttribute( 'status', eZContentObjectVersion::STATUS_DRAFT );
        $version->store();

        $this->password_hash = $this->buildPasswordHash( $this->login, "\n", $this->password ); 

        $user = eZUser::create( $userID );
        $user->setAttribute( 'login', $this->login );
        $user->setAttribute( 'email', $this->email );
        $user->setAttribute( 'password_hash', $this->password_hash );
        $user->setAttribute( 'password_hash_type', $this->password_hash_type );
        $user->store();

        $contentObjectAttributes = $version->contentObjectAttributes();

        // TODO - move this to ini settings
        $firstNameIdentifier = 'first_name';
        $lastNameIdentifier = 'last_name';

        $firstNameAttribute = null;
        $lastNameAttribute = null;

        $parentNodeIDs[] = $defaultUserPlacement;
        $parentNodeIDs = array_unique( $parentNodeIDs );

        foreach( $contentObjectAttributes as $attribute )
        {
            if ( $attribute->attribute( 'contentclass_attribute_identifier' ) == $firstNameIdentifier )
            {
                $firstNameAttribute = $attribute;
            }
            else if ( $attribute->attribute( 'contentclass_attribute_identifier' ) == $lastNameIdentifier )
            {
                $lastNameAttribute = $attribute;
            }
        }

       if ( $firstNameAttribute )
        {
            $firstNameAttribute->setAttribute( 'data_text', $this->first_name );
            $firstNameAttribute->store();
        }
        if ( $lastNameAttribute )
        {
            $lastNameAttribute->setAttribute( 'data_text', $this->last_name );
            $lastNameAttribute->store();
        }

        $contentClass = $userObject->attribute( 'content_class' );
        $name = $contentClass->contentObjectName( $userObject );

       $userObject->setName( $name );

        reset( $parentNodeIDs );

        // prepare node assignments for publishing new user
        foreach( $parentNodeIDs as $parentNodeID )
        {
            $newNodeAssignment = eZNodeAssignment::create( array( 'contentobject_id' => $userObjectID,
                'contentobject_version' => 1,
                'parent_node' => $parentNodeID,
                'is_main' => ( $defaultUserPlacement == $parentNodeID ? 1 : 0 ) ) );
            $newNodeAssignment->setAttribute( 'parent_remote_id', $parentNodeID );
            $newNodeAssignment->store();
        }

        $operationResult = eZOperationHandler::execute( 'content', 'publish', array( 'object_id' => $userObjectID, 'version' => 1 ) );

        eZUser::updateLastVisit( $userID );
        // Reset number of failed login attempts
        eZUser::setFailedLoginAttempts( $userID, 0 );

    }

    /**
     * This is how ezpublish generates passwords
     *
     * @param $login    String  Username
     * @param $del      String  Delimeter, usually \n
     * @param $key      String  The Password
     */
    private function buildPasswordHash( $login, $del, $key ){
        return md5(( $login.$del.$key ));
    }
}


