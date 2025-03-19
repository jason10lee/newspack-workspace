/* eslint @wordpress/no-unsafe-wp-apis: 0 */
import { createRoot } from 'react-dom/client';
import {
	__experimentalHeading as Heading,
	__experimentalText as Text,
	__experimentalVStack as VStack,
	__experimentalHStack as HStack,
	Button,
} from '@wordpress/components';

import { registerStore } from '../store';

import './style.scss';

import Stories from '../components/stories';

registerStore();

const StoryBudget = () => {
	return (
		<div className="wrap">
			<VStack spacing="4" className="newspack-story-budget-header">
				<HStack spacing="4">
					<Heading level={ 1 }>Story Budget</Heading>
					<HStack
						spacing="4"
						direction="row-reverse"
						expanded={ false }
					>
						<Button variant="primary">Manage Budgets</Button>
						<Button variant="link">Add New Budget</Button>
						<Button variant="link">Add Story to Budget</Button>
					</HStack>
				</HStack>
				<Text color="#757575" isBlock>
					Manage your story budget.
				</Text>
			</VStack>
			<Stories />
		</div>
	);
};

createRoot( document.getElementById( 'newspack-story-budget-app' ) ).render(
	<StoryBudget />
);
