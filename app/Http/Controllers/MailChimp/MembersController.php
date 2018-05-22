<?php
declare(strict_types=1);

namespace App\Http\Controllers\MailChimp;

use App\Database\Entities\MailChimp\MailChimpList;
use App\Database\Entities\MailChimp\MailChimpMember;
use App\Http\Controllers\Controller;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mailchimp\Mailchimp;

class MembersController extends Controller
{
    /**
     * @var \Mailchimp\Mailchimp
     */
    private $mailChimp;

    /**
     * MembersController constructor.
     *
     * @param \Doctrine\ORM\EntityManagerInterface $entityManager
     * @param \Mailchimp\Mailchimp $mailchimp
     */
    public function __construct(EntityManagerInterface $entityManager, Mailchimp $mailchimp)
    {
        parent::__construct($entityManager);

        $this->mailChimp = $mailchimp;
    }

    /**
     * Retrieve and return members for a MailChimp list.
     *
     * @param string $listId
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function list(string $listId): JsonResponse
    {
        /** @var \App\Database\Entities\MailChimp\MailChimpList|null $list */
        $list = $this->entityManager->getRepository(MailChimpList::class)->find($listId);

        if ($list === null) {
            return $this->errorResponse(
                ['message' => \sprintf('MailChimpList[%s] not found', $listId)],
                404
            );
        }

        $members = $this->_getMembersByList($list);
        $response = [];
        foreach($members as $member){
            /* @var $member MailChimpMember */
            $response[] = $member->toArray();
        }
        return $this->successfulResponse($response);
    }

    /**
     * Add new member to list $listId
     *
     * @param Request $request
     *
     * @param string $listId
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function create(Request $request,string $listId): JsonResponse
    {
        // Instantiate entity
        $member = new MailChimpMember($request->all());
        // Validate entity
        $validator = $this->getValidationFactory()->make($member->toMailChimpArray(), $member->getValidationRules());

        if ($validator->fails()) {
            // Return error response if validation failed
            return $this->errorResponse([
                'message' => 'Invalid data given',
                'errors' => $validator->errors()->toArray()
            ]);
        }

        // get mailchimp list (so we can get the mc id)
        /** @var \App\Database\Entities\MailChimp\MailChimpList|null $list */
        $list = $this->entityManager->getRepository(MailChimpList::class)->find($listId);


        try {
            // Save member into db
            $this->saveEntity($member);
            // Save member into MailChimp
            $response = $this->mailChimp->post(\sprintf('lists/%s/members', $list->getMailChimpId()), $member->toMailChimpArray());
            // assign member to list
            $member->setMailChimpList($list);
            // Set MailChimp id on the member and save member into db
            $this->saveEntity($member->setMailChimpId($response->get('id')));

        } catch (Exception $exception) {
            // Return error response if something goes wrong
            return $this->errorResponse(['message' => $exception->getMessage()]);
        }

        return $this->successfulResponse($member->toArray());
    }

    /**
     * Update MailChimp member.
     *
     * @param \Illuminate\Http\Request $request
     * @param string $listId
     * @param string $memberId
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, string $listId, string $memberId): JsonResponse
    {

        /** @var \App\Database\Entities\MailChimp\MailChimpList|null $list */
        $list = $this->entityManager->getRepository(MailChimpList::class)->find($listId);

        /** @var \App\Database\Entities\MailChimp\MailChimpMember|null $member */
        $member = $this->entityManager->getRepository(MailChimpMember::class)->find($memberId);


        if ($member === null) {
            return $this->errorResponse(
                ['message' => \sprintf('MailChimpMember[%s] not found', $listId)],
                404
            );
        }

        // Update list properties
        $member->fill($request->all());

        // Validate entity
        $validator = $this->getValidationFactory()->make($member->toMailChimpArray(), $member->getValidationRules());

        if ($validator->fails()) {
            // Return error response if validation failed
            return $this->errorResponse([
                'message' => 'Invalid data given',
                'errors' => $validator->errors()->toArray()
            ]);
        }

        try {
            // Update list into database
            $this->saveEntity($member);
            // Update list into MailChimp
            $this->mailChimp->put(\sprintf('lists/%s/members/%s', $list->getMailChimpId(),$member->getMailChimpId()), $member->toMailChimpArray());
        } catch (Exception $exception) {
            return $this->errorResponse(['message' => $exception->getMessage()]);
        }

        return $this->successfulResponse($member->toArray());
    }

    /**
     * Remove MailChimp member.
     *
     * @param string $listId
     * @param string $memberId
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function remove(string $listId, string $memberId): JsonResponse
    {

        /** @var \App\Database\Entities\MailChimp\MailChimpList|null $list */
        $list = $this->entityManager->getRepository(MailChimpList::class)->find($listId);

        /** @var \App\Database\Entities\MailChimp\MailChimpMember|null $member */
        $member = $this->entityManager->getRepository(MailChimpMember::class)->find($memberId);

        if ($member === null) {
            return $this->errorResponse(
                ['message' => \sprintf('MailChimpMember[%s] not found', $listId)],
                404
            );
        }

        try {
            // Remove list from database
            $this->removeEntity($member);
            // Remove list from MailChimp
            $this->mailChimp->delete(\sprintf('lists/%s/members/%s', $list->getMailChimpId(),$member->getMailChimpId()));
        } catch (Exception $exception) {
            return $this->errorResponse(['message' => $exception->getMessage()]);
        }

        return $this->successfulResponse([]);
    }



    public function _getMembersByList(MailChimpList $mailChimpList)
    {
        /** @var \App\Database\Entities\MailChimp\MailChimpList|null $list */
        $members = $this->entityManager->getRepository(MailChimpMember::class)->findBy(['mailChimpList'=>$mailChimpList->getId()]);
        return $members;
    }
}