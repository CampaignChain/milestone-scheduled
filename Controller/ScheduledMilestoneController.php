<?php
/*
 * This file is part of the CampaignChain package.
 *
 * (c) CampaignChain, Inc. <info@campaignchain.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace CampaignChain\Milestone\ScheduledMilestoneBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use CampaignChain\CoreBundle\Entity\Milestone;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

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

    public function newAction(Request $request, $campaign)
    {
        $campaignService = $this->get('campaignchain.core.campaign');
        $campaign = $campaignService->getCampaign($campaign);

        $milestoneType = $this->get('campaignchain.core.form.type.milestone');
        $milestoneType->setBundleName(self::BUNDLE_NAME);
        $milestoneType->setModuleIdentifier(self::MODULE_IDENTIFIER);
        $milestoneType->setCampaign($campaign);

        $milestone = new Milestone();
        $milestone->setCampaign($campaign);

        $form = $this->createForm($milestoneType, $milestone);

        $form->handleRequest($request);

        if ($form->isValid()) {
            $em = $this->getDoctrine()->getManager();

            // Make sure that data stays intact by using transactions.
            try {
                $em->getConnection()->beginTransaction();

                $em->persist($milestone);
                // We need the milestone ID for storing the hooks. Hence we must flush here.
                $em->flush();

                $hookService = $this->get('campaignchain.core.hook');
                $milestone = $hookService->processHooks(self::BUNDLE_NAME, self::MODULE_IDENTIFIER, $milestone, $form, true);

                $em->flush();

                $em->getConnection()->commit();
            } catch (\Exception $e) {
                $em->getConnection()->rollback();
                throw $e;
            }

            $this->get('session')->getFlashBag()->add(
                'success',
                'Your new milestone <a href="'.$this->generateUrl('campaignchain_core_milestone_edit', array('id' => $milestone->getId())).'">'.$milestone->getName().'</a> was created successfully.'
            );

            return $this->redirect($this->generateUrl('campaignchain_core_milestone'));
        }

        return $this->render(
            'CampaignChainCoreBundle:Milestone:new.html.twig',
            array(
                'page_title' => 'Create Scheduled Milestone',
                'form' => $form->createView(),
                'milestone' => $milestone,
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
        $milestoneType->setCampaign($milestone->getCampaign());

        $form = $this->createForm($milestoneType, $milestone);

        $form->handleRequest($request);

        if ($form->isValid()) {
            $em = $this->getDoctrine()->getManager();

            $hookService = $this->get('campaignchain.core.hook');
            $milestone = $hookService->processHooks(self::BUNDLE_NAME, self::MODULE_IDENTIFIER, $milestone, $form);
            $em->persist($milestone);

            $em->flush();

            $this->get('session')->getFlashBag()->add(
                'success',
                'Your milestone <a href="'.$this->generateUrl('campaignchain_core_milestone_edit', array('id' => $milestone->getId())).'">'.$milestone->getName().'</a> was edited successfully.'
            );

            return $this->redirect($this->generateUrl('campaignchain_core_milestone'));
        }

        return $this->render(
            'CampaignChainCoreBundle:Milestone:new.html.twig',
            array(
            'page_title' => 'Edit Scheduled Milestone',
            'form' => $form->createView(),
            'milestone' => $milestone,
        ));
    }

    public function editModalAction(Request $request, $id){
        // TODO: If a milestone is over/done, it cannot be edited.
        $milestoneService = $this->get('campaignchain.core.milestone');
        $milestone = $milestoneService->getMilestone($id);

        $milestoneType = $this->get('campaignchain.core.form.type.milestone');
        $milestoneType->setBundleName(self::BUNDLE_NAME);
        $milestoneType->setModuleIdentifier(self::MODULE_IDENTIFIER);
        $milestoneType->setView('default');
        $milestoneType->setCampaign($milestone->getCampaign());

        $form = $this->createForm($milestoneType, $milestone);

        return $this->render(
            'CampaignChainCoreBundle:Base:new_modal.html.twig',
            array(
                'page_title' => 'Edit Scheduled Milestone',
                'form' => $form->createView(),
            ));
    }

    public function editApiAction(Request $request, $id)
    {
        $responseData = array();

        $data = $request->get('campaignchain_core_milestone');

        // $responseData['payload'] = $data;

        $milestoneService = $this->get('campaignchain.core.milestone');
        $milestone = $milestoneService->getMilestone($id);
        $milestone->setName($data['name']);

        $em = $this->getDoctrine()->getManager();
        $em->persist($milestone);

        $hookService = $this->get('campaignchain.core.hook');
        $hookService->processHooks(self::BUNDLE_NAME, self::MODULE_IDENTIFIER, $milestone, $data);

        $em->flush();

        $responseData['start_date'] =
        $responseData['end_date'] =
            $milestone->getStartDate()->format(\DateTime::ISO8601);

        $serializer = $this->get('campaignchain.core.serializer.default');

        return new Response($serializer->serialize($responseData, 'json'));
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