<?php

namespace Oro\Bundle\TestFrameworkBundle\Migrations\Data\ORM;

use Doctrine\Common\DataFixtures\AbstractFixture;
use Doctrine\Common\Persistence\ObjectManager;
use Oro\Bundle\SecurityBundle\Acl\Persistence\AclManager;
use Oro\Bundle\UserBundle\Entity\Role;
use Oro\Bundle\UserBundle\Entity\User;
use Oro\Bundle\UserBundle\Entity\UserApi;
use Oro\Bundle\UserBundle\Entity\UserManager;
use Oro\Bundle\UserBundle\Migrations\Data\ORM\LoadAdminUserData;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

abstract class AbstractLoadMultipleUserData extends AbstractFixture implements ContainerAwareInterface
{
    /**
     * @var ContainerInterface
     */
    protected $container;

    /**
     * {@inheritdoc}
     */
    public function setContainer(ContainerInterface $container = null)
    {
        $this->container = $container;
    }

    /**
     * {@inheritdoc}
     */
    public function getDependencies()
    {
        return [
            'Oro\Bundle\UserBundle\Migrations\Data\ORM\LoadAdminUserData',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function load(ObjectManager $manager)
    {
        $this->loadRoles($manager);
        $this->loadUsers($manager);
    }

    /**
     * @param ObjectManager $manager
     */
    protected function loadRoles(ObjectManager $manager)
    {
        /* @var $aclManager AclManager */
        $aclManager = $this->container->get('oro_security.acl.manager');

        foreach ($this->getRolesData() as $key => $items) {
            $role = new Role($key);
            $role->setLabel($key);
            $manager->persist($role);

            foreach ($items as $acls) {
                $className = $this->container->getParameter($acls['class']);

                $this->setRolePermissions($aclManager, $role, $className, $acls['acls']);
            }

            $this->setReference($key, $role);
        }

        $manager->flush();
        $aclManager->flush();
    }

    /**
     * @param ObjectManager $manager
     */
    protected function loadUsers(ObjectManager $manager)
    {
        /* @var $userManager UserManager */
        $userManager = $this->container->get('oro_user.manager');

        $defaultUser = $this->getUser($manager);

        $businessUnit = $defaultUser->getOwner();
        $organization = $defaultUser->getOrganization();

        foreach ($this->getUsersData() as $item) {
            $user = $userManager->createUser();

            $apiKey = new UserApi();
            $apiKey
                ->setApiKey($item['password'])
                ->setUser($user)
                ->setOrganization($organization);

            $user
                ->setEmail($item['email'])
                ->setFirstName($item['firstname'])
                ->setLastName($item['lastname'])
                ->setBusinessUnits($defaultUser->getBusinessUnits())
                ->setOwner($businessUnit)
                ->setOrganization($organization)
                ->addOrganization($organization)
                ->setUsername($item['username'])
                ->setPlainPassword($item['password'])
                ->setEnabled(true)
                ->addApiKey($apiKey);

            foreach ($item['roles'] as $role) {
                /** @var Role $roleEntity */
                $roleEntity = $this->getReference($role);
                $user->addRole($roleEntity);
            }

            $userManager->updateUser($user);

            $this->setReference($user->getUsername(), $user);
        }
    }

    /**
     * @param AclManager $aclManager
     * @param Role $role
     * @param string $className
     * @param array $allowedAcls
     */
    protected function setRolePermissions(AclManager $aclManager, Role $role, $className, array $allowedAcls)
    {
        if ($aclManager->isAclEnabled()) {
            $sid = $aclManager->getSid($role);
            $oid = $aclManager->getOid('entity:' . $className);

            $builder = $aclManager->getMaskBuilder($oid);
            $mask = $builder->reset()->get();
            foreach ($allowedAcls as $acl) {
                $mask = $builder->add($acl)->get();
            }
            $aclManager->setPermission($sid, $oid, $mask);
        }
    }

    /**
     * @param ObjectManager $manager
     * @return User
     * @throws \LogicException
     */
    protected function getUser(ObjectManager $manager)
    {
        /* @var $user User */
        $user = $manager->getRepository('OroUserBundle:User')->findOneBy([
            'email' => LoadAdminUserData::DEFAULT_ADMIN_EMAIL,
        ]);

        if (!$user) {
            throw new \LogicException('There are no users in system');
        }

        return $user;
    }

    /**
     * @return array
     */
    abstract protected function getRolesData();

    /**
     * @return array
     */
    abstract protected function getUsersData();
}