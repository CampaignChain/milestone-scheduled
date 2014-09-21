<?php
/*
 * This file is part of the CampaignChain package.
 *
 * (c) Sandro Groganz <sandro@campaignchain.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace CampaignChain\Milestone\ScheduledMilestoneBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use CampaignChain\CoreBundle\Entity\Milestone;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\GetSetMethodNormalizer;

class ScheduledMilestoneController extends Controller
{
    const BUNDLE_NAME = 'campaignchain/milestone-scheduled';
    const MODULE_IDENTIFIER = 'campaignchain-scheduled';
    const FORMAT_DATEINTERVAL = 'Years: %Y, months: %m, days: %d, hours: %h, minutes: %i, seconds: %s';

    public function indexAction(){
        $em = $this->getDoctrine()->getManager();
        $qb = $em->createQueryBuilder();
        $qb->select('m')
            ->from('CampaignChain\CoreBundle\Entity\Milestone', 'm')
            ->where('m.startDate IS NOT NULL')
            ->andWhere('m.endDate IS NULL')
            ->orderBy('m.startDate');
        $query = $qb->getQuery();
        $milestones = $query->getResult();

        return $this->render(
            'CampaignChainCoreBundle:Milestone:index.html.twig',
            array(
                'page_title' => 'Milestones',
                'milestones' => $milestones
            ));
    }

    public function newAction(Request $request)
    {
        $milestoneType = $this->get('campaignchain.core.form.type.milestone');
        $milestoneType->setBundleName(self::BUNDLE_NAME);
        $milestoneType->setModuleIdentifier(self::MODULE_IDENTIFIER);
        //$milestoneType->setCampaign($milestone);

        $milestone = new Milestone();

        $form = $this->createForm($milestoneType, $milestone);

        $form->handleRequest($request);

        if ($form->isValid()) {
            $repository = $this->getDoctrine()->getManager();

            // Make sure that data stays intact by using transactions.
            try {
                $repository->getConnection()->beginTransaction();

                $repository->persist($milestone);
                // We need the milestone ID for storing the hooks. Hence we must flush here.
                $repository->flush();

                $hookService = $this->get('campaignchain.core.hook');
                $milestone = $hookService->processHooks(self::BUNDLE_NAME, self::MODULE_IDENTIFIER, $milestone, $form, true);

                $repository->flush();

                $repository->getConnection()->commit();
            } catch (\Exception $e) {
                $repository->getConnection()->rollback();
                throw $e;
            }

            $this->get('session')->getFlashBag()->add(
                'success',
                'Your new milestone <a href="'.$this->generateUrl('campaignchain_core_milestone_edit', array('id' => $milestone->getId())).'">'.$milestone->getName().'</a> was created successfully.'
            );

            return $this->redirect($this->generateUrl('campaignchain_core_milestone'));
        }

        // Retrieve campaign data for drop-down list
        $campaignService = $this->get('campaignchain.core.campaign');
        $campaignsDatesJson = $campaignService->getCampaignsDatesJson();

        return $this->render(
            'CampaignChainMilestoneScheduledMilestoneBundle:ScheduledMilestone:new.html.twig',
            array(
                'page_title' => 'Create New Milestone',
                'form' => $form->createView(),
                'campaigns_dates' => $campaignsDatesJson,
            ));

        return $this->form($request, $milestone, 'Create New Milestone');
    }

    public function editAction(Request $request, $id){
        // TODO: If a milestone is over/done, it cannot be edited.
        $milestoneService = $this->get('campaignchain.core.milestone');
        $milestone = $milestoneService->getMilestone($id);

        $milestoneType = $this->get('campaignchain.core.form.type.milestone');
        $milestoneType->setBundleName(self::BUNDLE_NAME);
        $milestoneType->setModuleIdentifier(self::MODULE_IDENTIFIER);

        $form = $this->createForm($milestoneType, $milestone);

        $form->handleRequest($request);

        if ($form->isValid()) {
            $repository = $this->getDoctrine()->getManager();

            $hookService = $this->get('campaignchain.core.hook');
            $milestone = $hookService->processHooks(self::BUNDLE_NAME, self::MODULE_IDENTIFIER, $milestone, $form);
            $repository->persist($milestone);

            $repository->flush();

            $this->get('session')->getFlashBag()->add(
                'success',
                'Your milestone <a href="'.$this->generateUrl('campaignchain_core_milestone_edit', array('id' => $milestone->getId())).'">'.$milestone->getName().'</a> was edited successfully.'
            );

            return $this->redirect($this->generateUrl('campaignchain_core_milestone'));
        }

        return $this->render(
            'CampaignChainCoreBundle:Base:new.html.twig',
            array(
            'page_title' => 'Edit Milestone',
            'form' => $form->createView(),
        ));
    }

    public function editModalAction(Request $request, $id){
        // TODO: If a milestone is over/done, it cannot be edited.
        $milestoneService = $this->get('campaignchain.core.milestone');
        $milestone = $milestoneService->getMilestone($id);

        $milestoneType = $this->get('campaignchain.core.form.type.milestone');
        $milestoneType->setBundleName(self::BUNDLE_NAME);
        $milestoneType->setModuleIdentifier(self::MODULE_IDENTIFIER);
        $milestoneType->setView('modal');

        $form = $this->createForm($milestoneType, $milestone);

        return $this->render(
            'CampaignChainCoreBundle:Base:new_modal.html.twig',
            array(
                'page_title' => 'Edit Milestone',
                'form' => $form->createView(),
            ));
    }

    public function editApiAction(Request $request, $id)
    {
        $responseData = array();

        $data = $request->get('campaignchain_core_milestone');

        $responseData['data'] = $data;

        $milestoneService = $this->get('campaignchain.core.milestone');
        $milestone = $milestoneService->getMilestone($id);
        $milestone->setName($data['name']);

        $repository = $this->getDoctrine()->getManager();
        $repository->persist($milestone);

        $hookService = $this->get('campaignchain.core.hook');
        $milestone = $hookService->processHooks(self::BUNDLE_NAME, self::MODULE_IDENTIFIER, $milestone, $data);

        $repository->flush();

        $encoders = array(new JsonEncoder());
        $normalizers = array(new GetSetMethodNormalizer());
        $serializer = new Serializer($normalizers, $encoders);

        $response = new Response($serializer->serialize($responseData, 'json'));
        return $response->setStatusCode(Response::HTTP_OK);
    }

    public function moveApiAction(Request $request)
    {
        $encoders = array(new JsonEncoder());
        $normalizers = array(new GetSetMethodNormalizer());
        $serializer = new Serializer($normalizers, $encoders);

        $responseData = array();

        $id = $request->request->get('id');
        $newDue = new \DateTime($request->request->get('due_date'));

        $milestone = $this->getMilestone($id);
        $responseData['id'] = $milestone->getId();

        $oldDue = clone $milestone->getDue();
        $responseData['old_due_date'] = $oldDue->format(\DateTime::ISO8601);

        // Calculate time difference.
        $interval = $milestone->getDue()->diff($newDue);
        $responseData['interval']['object'] = json_encode($interval, true);
        $responseData['interval']['string'] = $interval->format(self::FORMAT_DATEINTERVAL);

        // Set new due date.
        $milestone->setDue(new \DateTime($milestone->getDue()->add($interval)->format(\DateTime::ISO8601)));
        $responseData['new_due_date'] = $milestone->getDue()->format(\DateTime::ISO8601);

        $repository = $this->getDoctrine()->getManager();
        $repository->flush();

        $response = new Response($serializer->serialize($responseData, 'json'));
        return $response->setStatusCode(Response::HTTP_OK);
    }

    public function getMilestone($id){
        $milestone = $this->getDoctrine()
            ->getRepository('CampaignChainCoreBundle:Milestone')
            ->find($id);

        if (!$milestone) {
            throw new \Exception(
                'No milestone found for id '.$id
            );
        }

        return $milestone;
    }
}