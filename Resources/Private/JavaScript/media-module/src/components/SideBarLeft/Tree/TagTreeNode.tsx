import * as React from 'react';
import { useCallback } from 'react';

import { Tree } from '@neos-project/react-ui-components';

import { AssetCollection, Tag } from '@media-ui/core/src/interfaces';
import dndTypes from '@media-ui/core/src/constants/dndTypes';

import TreeNodeProps from './TreeNodeProps';

export interface TagTreeNodeProps extends TreeNodeProps {
    tag: Tag;
    assetCollection?: AssetCollection;
    onClick: (tag: Tag, assetCollection?: AssetCollection) => void;
}

const TagTreeNode: React.FC<TagTreeNodeProps> = ({
    tag,
    isActive,
    isFocused,
    assetCollection,
    label,
    title,
    onClick,
    level,
}: TagTreeNodeProps) => {
    const handleClick = useCallback(() => onClick(tag, assetCollection), [onClick, tag, assetCollection]);

    return (
        <Tree.Node>
            <Tree.Node.Header
                isActive={isActive}
                isCollapsed={true}
                isFocused={isFocused !== undefined ? isFocused : isActive}
                isLoading={false}
                hasError={false}
                label={label || tag.label}
                title={title || tag.label}
                icon="tag"
                nodeDndType={dndTypes.TAG}
                level={level}
                onClick={handleClick}
                hasChildren={false}
            />
        </Tree.Node>
    );
};

export default React.memo(TagTreeNode);
