// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/*
Crucible Applications Landing Page Block for Moodle

Copyright 2024 Carnegie Mellon University.

NO WARRANTY. THIS CARNEGIE MELLON UNIVERSITY AND SOFTWARE ENGINEERING INSTITUTE MATERIAL IS FURNISHED ON AN "AS-IS" BASIS.
CARNEGIE MELLON UNIVERSITY MAKES NO WARRANTIES OF ANY KIND, EITHER EXPRESSED OR IMPLIED, AS TO ANY MATTER INCLUDING, BUT NOT LIMITED TO,
WARRANTY OF FITNESS FOR PURPOSE OR MERCHANTABILITY, EXCLUSIVITY, OR RESULTS OBTAINED FROM USE OF THE MATERIAL.
CARNEGIE MELLON UNIVERSITY DOES NOT MAKE ANY WARRANTY OF ANY KIND WITH RESPECT TO FREEDOM FROM PATENT, TRADEMARK, OR COPYRIGHT INFRINGEMENT.
Licensed under a GNU GENERAL PUBLIC LICENSE - Version 3, 29 June 2007-style license, please see license.txt or contact permission@sei.cmu.edu for full terms.

[DISTRIBUTION STATEMENT A] This material has been approved for public release and unlimited distribution. Please see Copyright notice for non-US Government use and distribution.

This Software includes and/or makes use of Third-Party Software each subject to its own license.

DM24-1176
*/

/**
 * Drag-and-drop application reordering
 *
 * @module     block_crucible/apporder
 * @copyright  2024 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {call} from 'core/ajax';
import Notification from 'core/notification';

let draggedElement = null;
let placeholder = null;

/**
 * Create placeholder element for drop position
 * @returns {HTMLElement} - Placeholder element
 */
const createPlaceholder = () => {
    if (!placeholder) {
        placeholder = document.createElement('div');
        placeholder.className = 'crucible-drop-placeholder';
    }
    return placeholder;
};

/**
 * Initialize drag-and-drop for application cards
 * @param {String} containerSelector - CSS selector for apps container
 */
export const init = (containerSelector = '.apps-grid') => {
    const container = document.querySelector(containerSelector);
    if (!container) {
        return;
    }

    const cards = container.querySelectorAll('.app-card');
    if (cards.length === 0) {
        return;
    }

    // Make each card draggable
    cards.forEach(card => {
        card.setAttribute('draggable', 'true');
        card.classList.add('draggable-app');

        card.addEventListener('dragstart', handleDragStart);
        card.addEventListener('dragend', handleDragEnd);
        card.addEventListener('dragover', handleDragOver);
        card.addEventListener('drop', handleDrop);
        card.addEventListener('dragenter', handleDragEnter);
        card.addEventListener('dragleave', handleDragLeave);
    });
};

/**
 * Handle drag start event
 * @param {Event} e - Drag event
 */
const handleDragStart = (e) => {
    draggedElement = e.currentTarget;
    e.currentTarget.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/html', e.currentTarget.innerHTML);
};

/**
 * Handle drag end event
 * @param {Event} e - Drag event
 */
const handleDragEnd = (e) => {
    e.currentTarget.classList.remove('dragging');

    // Remove placeholder
    if (placeholder && placeholder.parentNode) {
        placeholder.parentNode.removeChild(placeholder);
    }

    document.querySelectorAll('.app-card').forEach(card => {
        card.classList.remove('drag-over');
    });

    saveOrder().catch(error => {
        console.error('Unhandled error in saveOrder:', error);
    });
};

/**
 * Handle drag over event
 * @param {Event} e - Drag event
 * @returns {Boolean} - Always returns false
 */
const handleDragOver = (e) => {
    if (e.preventDefault) {
        e.preventDefault();
    }
    e.dataTransfer.dropEffect = 'move';

    const dropTarget = e.currentTarget;
    if (dropTarget !== draggedElement) {
        const container = dropTarget.parentNode;
        const allCards = Array.from(container.querySelectorAll('.app-card'));
        const draggedIndex = allCards.indexOf(draggedElement);
        const targetIndex = allCards.indexOf(dropTarget);

        // Show placeholder line
        const placeholderElement = createPlaceholder();

        if (draggedIndex < targetIndex) {
            // Dropping after target (moving down/right)
            container.insertBefore(placeholderElement, dropTarget.nextSibling);
        } else {
            // Dropping before target (moving up/left)
            container.insertBefore(placeholderElement, dropTarget);
        }
    }

    return false;
};

/**
 * Handle drag enter event
 * @param {Event} e - Drag event
 */
const handleDragEnter = (e) => {
    // Intentionally minimal - dragover handles the visual feedback
};

/**
 * Handle drag leave event
 * @param {Event} e - Drag event
 */
const handleDragLeave = (e) => {
    // Intentionally minimal - dragover handles the visual feedback
};

/**
 * Handle drop event
 * @param {Event} e - Drag event
 * @returns {Boolean} - Always returns false
 */
const handleDrop = (e) => {
    if (e.stopPropagation) {
        e.stopPropagation();
    }

    const dropTarget = e.currentTarget;

    if (draggedElement !== dropTarget) {
        // Get the placeholder's position before removing it
        const placeholderParent = placeholder ? placeholder.parentNode : null;
        const placeholderNext = placeholder ? placeholder.nextSibling : null;

        // Remove placeholder first to avoid interference
        if (placeholder && placeholder.parentNode) {
            placeholder.parentNode.removeChild(placeholder);
        }

        // Insert dragged element where the placeholder was
        if (placeholderParent && placeholderNext) {
            placeholderParent.insertBefore(draggedElement, placeholderNext);
        } else if (placeholderParent) {
            placeholderParent.appendChild(draggedElement);
        }
    }

    return false;
};

/**
 * Save current order to user preferences
 */
const saveOrder = async() => {
    const container = document.querySelector('.apps-grid');
    if (!container) {
        return;
    }

    const cards = container.querySelectorAll('.app-card');
    const order = Array.from(cards).map(card => card.getAttribute('id'));

    try {
        await call([{
            methodname: 'block_crucible_save_app_order',
            args: {
                order: JSON.stringify(order)
            }
        }]);

        console.log('Application order saved successfully');
    } catch (error) {
        console.error('Error saving app order:', error);

        Notification.exception(error);
    }
};
