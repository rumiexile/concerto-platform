<?php

namespace Concerto\PanelBundle\Service;

use Concerto\PanelBundle\Entity\TestVariable;
use Symfony\Component\Validator\Validator\RecursiveValidator;
use Concerto\PanelBundle\Entity\Test;
use Concerto\PanelBundle\Repository\TestRepository;
use Concerto\PanelBundle\Repository\TestVariableRepository;
use Concerto\PanelBundle\Service\TestNodePortService;
use Concerto\PanelBundle\Entity\User;
use Symfony\Component\Security\Core\Authorization\AuthorizationChecker;
use Concerto\PanelBundle\Security\ObjectVoter;

class TestVariableService extends ASectionService {

    private $validator;
    private $testNodePortService;
    private $testRepository;

    public function __construct(TestVariableRepository $repository, RecursiveValidator $validator, TestNodePortService $portService, TestRepository $testRepository, AuthorizationChecker $securityAuthorizationChecker) {
        parent::__construct($repository, $securityAuthorizationChecker);

        $this->validator = $validator;
        $this->testNodePortService = $portService;
        $this->testRepository = $testRepository;
    }

    public function get($object_id, $createNew = false, $secure = true) {
        $object = parent::get($object_id, $createNew, $secure);
        if ($createNew && $object === null) {
            $object = new TestVariable();
        }
        return $object;
    }

    public function getAllVariables($test_id) {
        return $this->authorizeCollection($this->repository->findByTest($test_id));
    }

    public function getParameters($test_id) {
        return $this->authorizeCollection($this->repository->findByTestAndType($test_id, 0));
    }

    public function getReturns($test_id) {
        return $this->authorizeCollection($this->repository->findByTestAndType($test_id, 1));
    }

    public function getBranches($test_id) {
        return $this->authorizeCollection($this->repository->findByTestAndType($test_id, 2));
    }

    public function saveCollection(User $user, $serializedVariables, Test $test) {
        $result = array("errors" => array());
        if (!$serializedVariables)
            return $result;
        $variables = json_decode($serializedVariables, true);

        for ($i = 0; $i < count($variables); $i++) {
            $var = $variables[$i];
            $parentVariable = null;
            if ($var["parentVariable"])
                $parentVariable = $this->repository->find($var["parentVariable"]);
            $r = $this->save($user, $var["id"], $var["name"], $var["type"], $var["description"], $var["passableThroughUrl"], array_key_exists("value", $var) ? $var["value"] : null, $test, $parentVariable);
            if (count($r["errors"]) > 0) {
                for ($a = 0; $a < count($r["errors"]); $a++) {
                    array_push($result["errors"], $r["errors"][$a]);
                }
            }
        }
        return $result;
    }

    public function save(User $user, $object_id, $name, $type, $description, $passableThroughUrl, $value, $test, $parentVariable = null) {
        $errors = array();
        $object = $this->get($object_id);
        $is_new = false;
        if ($object === null) {
            $object = new TestVariable();
            $is_new = true;
        }
        $object->setUpdated();
        $object->setName($name);
        $object->setType($type);
        if ($description !== null) {
            $object->setDescription($description);
        }
        if ($passableThroughUrl !== null) {
            $object->setPassableThroughUrl($passableThroughUrl == 1);
        }
        if (trim($value) == null) {
            $value = null;
        }
        $object->setParentVariable($parentVariable);
        $object->setValue($value);
        $object->setTest($test);
        foreach ($this->validator->validate($object) as $err) {
            array_push($errors, $err->getMessage());
        }
        if (count($errors) > 0) {
            return array("object" => null, "errors" => $errors);
        }
        $this->repository->save($object);
        $this->repository->refresh($object);
        $object = $this->get($object->getId());
        $this->onObjectSaved($user, $object, $is_new);
        return array("object" => $object, "errors" => $errors);
    }

    public function createVariablesFromSourceTest(User $user, Test $dstTest) {
        $wizard = $dstTest->getSourceWizard();
        foreach ($wizard->getTest()->getVariables() as $variable) {
            $description = $variable->getDescription();
            $name = $variable->getName();
            $url = $variable->isPassableThroughUrl();
            $type = $variable->getType();
            $value = $variable->getValue();

            foreach ($wizard->getParams() as $param) {
                if ($param->getVariable()->getId() === $variable->getId()) {
                    $description = $param->getDescription();
                    $url = $param->isPassableThroughUrl();
                    $value = $param->getValue();
                    break;
                }
            }

            $this->save($user, 0, $name, $type, $description, $url, $value, $dstTest, $variable);
        }
    }

    private function updateChildVariables(User $user, TestVariable $parentVariable) {
        $description = $parentVariable->getDescription();
        $name = $parentVariable->getName();
        $url = $parentVariable->isPassableThroughUrl();
        $type = $parentVariable->getType();
        $value = $parentVariable->getValue();

        foreach ($parentVariable->getTest()->getWizards() as $wizard) {
            foreach ($wizard->getParams() as $param) {
                if ($param->getVariable()->getId() === $parentVariable->getId()) {
                    $description = $param->getDescription();
                    $url = $param->isPassableThroughUrl();
                    $value = $param->getValue();
                    break;
                }
            }

            foreach ($wizard->getResultingTests() as $test) {
                $found = false;
                foreach ($test->getVariables() as $variable) {
                    if ($variable->getParentVariable() && $variable->getParentVariable()->getId() == $parentVariable->getId()) {
                        $found = true;
                        $this->save($user, $variable->getId(), $name, $type, $description, $url, $value, $test, $parentVariable);
                        break;
                    }
                }
                if (!$found) {
                    $this->save($user, 0, $name, $type, $description, $url, $value, $test, $parentVariable);
                }
            }
        }
    }

    private function onObjectSaved(User $user, TestVariable $object, $is_new) {
        $this->updateChildVariables($user, $object);
        $this->testNodePortService->onTestVariableSaved($user, $object, $is_new);
    }

    public function delete($object_ids, $secure = true) {
        $object_ids = explode(",", $object_ids);

        $result = array();
        foreach ($object_ids as $object_id) {
            $object = $this->get($object_id, false, $secure);
            if ($object === null)
                continue;
            $this->repository->delete($object);
            array_push($result, array("object" => $object, "errors" => array()));
        }
        return $result;
    }

    public function deleteAll($test_id, $type) {
        $test = parent::authorizeObject($this->testRepository->find($test_id));
        if ($test)
            $this->repository->deleteByTestAndType($test_id, $type);
    }

    public function importFromArray(User $user, $newName, $obj, &$map, &$queue) {
        $pre_queue = array();
        if (array_key_exists("TestVariable", $map) && array_key_exists("id" . $obj["id"], $map["TestVariable"])) {
            return(array());
        }

        $test = null;
        if ($obj["test"]) {
            if (array_key_exists("Test", $map) && array_key_exists("id" . $obj["test"], $map["Test"])) {
                $test_id = $map["Test"]["id" . $obj["test"]];
                $test = $this->testRepository->find($test_id);
            }
        }

        $parentVariable = null;
        if (array_key_exists("TestVariable", $map) && $obj["parentVariable"]) {
            $parentVariable_id = $map["TestVariable"]["id" . $obj["parentVariable"]];
            $parentVariable = $this->repository->find($parentVariable_id);
        }

        if (count($pre_queue) > 0) {
            return array("pre_queue" => $pre_queue);
        }

        $ent = new TestVariable();
        $ent->setName($obj["name"]);
        $ent->setDescription($obj["description"]);
        $ent->setTest($test);
        $ent->setType($obj["type"]);
        $ent->setPassableThroughUrl($obj["passableThroughUrl"]);
        $ent->setValue($obj['value']);
        $ent->setParentVariable($parentVariable);

        $ent_errors = $this->validator->validate($ent);
        $ent_errors_msg = array();
        foreach ($ent_errors as $err) {
            array_push($ent_errors_msg, $err->getMessage());
        }
        if (count($ent_errors_msg) > 0) {
            return array("errors" => $ent_errors_msg, "entity" => null, "source" => $obj);
        }
        $this->repository->save($ent);

        if (!array_key_exists("TestVariable", $map)) {
            $map["TestVariable"] = array();
        }
        $map["TestVariable"]["id" . $obj["id"]] = $ent->getId();

        return array("errors" => null, "entity" => $ent);
    }

    public function entityToArray(TestVariable $ent) {
        $e = $ent->jsonSerialize();
        return $e;
    }

    public function authorizeObject($object) {
        if ($object && $this->securityAuthorizationChecker->isGranted(ObjectVoter::ATTR_ACCESS, $object->getTest()))
            return $object;
        return null;
    }

}
