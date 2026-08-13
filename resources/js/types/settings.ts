export type Language = 'en' | 'ru' | 'tg' | 'fa';
export type ThemeMode = 'cosmic' | 'midnight' | 'cyberpunk' | 'light';
export type LayoutMode =
    | 'hierarchy'
    | 'spiral'
    | 'rings'
    | 'grid'
    | 'radial'
    | 'layered'
    | 'cluster'
    | 'force';

/**
 * Структура связей пространства. В отличие от остальных настроек живёт не в
 * localStorage, а в БД: это свойство самого пространства, общее для всех.
 */
/** Куда растут уровни в иерархических раскладках. */
export type LayoutDirection = 'down' | 'up' | 'right' | 'left';

/** По какому признаку красить узлы: по дереву-владельцу или по уровню. */
export type ColorMode = 'tree' | 'depth';

export type SpaceStructure = 'tree' | 'leveled' | 'dag' | 'network';

export const SPACE_STRUCTURES: SpaceStructure[] = [
    'tree',
    'leveled',
    'dag',
    'network',
];

export interface AppSettings {
    lang: Language;
    theme: ThemeMode;
    layoutMode: LayoutMode;
    layoutDirection: LayoutDirection;
    colorMode: ColorMode;
    // Сцена
    curvedEdges: boolean;
    showGrid: boolean;
    showAxes: boolean;
    showNodeLabels: boolean;
    nodeScale: number;
    reduceMotion: boolean;
    // Интерфейс
    showMinimap: boolean;
    showStats: boolean;
    compactHud: boolean;
}

export const DEFAULT_SETTINGS: AppSettings = {
    lang: 'en',
    theme: 'cosmic',
    layoutMode: 'hierarchy',
    layoutDirection: 'down',
    colorMode: 'tree',
    curvedEdges: true,
    showGrid: true,
    showAxes: false,
    showNodeLabels: true,
    nodeScale: 1,
    reduceMotion: false,
    showMinimap: true,
    showStats: true,
    compactHud: false,
};

export const NODE_SCALE_MIN = 0.5;
export const NODE_SCALE_MAX = 2.5;

const STORAGE_KEY = 'infinite-space-settings';

const LANGUAGES: Language[] = ['en', 'ru', 'tg', 'fa'];
const THEMES: ThemeMode[] = ['cosmic', 'midnight', 'cyberpunk', 'light'];
const DIRECTIONS: LayoutDirection[] = ['down', 'up', 'right', 'left'];
const COLOR_MODES: ColorMode[] = ['tree', 'depth'];

const LAYOUTS: LayoutMode[] = [
    'hierarchy',
    'spiral',
    'rings',
    'grid',
    'radial',
    'layered',
    'cluster',
    'force',
];

export const RTL_LANGUAGES: Language[] = ['fa'];

/** Цвета сцены под каждую тему — внутри канваса CSS-переменные недоступны. */
export interface ThemeTokens {
    canvasBg: string;
    gridMajor: string;
    gridMinor: string;
    accent: string;
    labelBg: string;
    labelText: string;
}

export const THEME_TOKENS: Record<ThemeMode, ThemeTokens> = {
    cosmic: {
        canvasBg: '#090d16',
        gridMajor: '#1e293b',
        gridMinor: '#0f172a',
        accent: '#38bdf8',
        labelBg: 'rgba(15, 23, 42, 0.75)',
        labelText: '#f8fafc',
    },
    midnight: {
        canvasBg: '#0b1224',
        gridMajor: '#1e3a5f',
        gridMinor: '#132244',
        accent: '#38bdf8',
        labelBg: 'rgba(12, 22, 49, 0.78)',
        labelText: '#f0f5fb',
    },
    cyberpunk: {
        canvasBg: '#180828',
        gridMajor: '#3b1a63',
        gridMinor: '#25103f',
        accent: '#e879f9',
        labelBg: 'rgba(27, 11, 46, 0.78)',
        labelText: '#f5eefc',
    },
    light: {
        canvasBg: '#f1f5f9',
        gridMajor: '#cbd5e1',
        gridMinor: '#e2e8f0',
        accent: '#2563eb',
        labelBg: 'rgba(255, 255, 255, 0.88)',
        labelText: '#0f172a',
    },
};

/** Приводит произвольный объект из localStorage к валидным настройкам. */
function sanitize(raw: Partial<AppSettings>): AppSettings {
    const merged = { ...DEFAULT_SETTINGS, ...raw };

    return {
        ...merged,
        lang: LANGUAGES.includes(merged.lang)
            ? merged.lang
            : DEFAULT_SETTINGS.lang,
        theme: THEMES.includes(merged.theme)
            ? merged.theme
            : DEFAULT_SETTINGS.theme,
        layoutMode: LAYOUTS.includes(merged.layoutMode)
            ? merged.layoutMode
            : DEFAULT_SETTINGS.layoutMode,
        layoutDirection: DIRECTIONS.includes(merged.layoutDirection)
            ? merged.layoutDirection
            : DEFAULT_SETTINGS.layoutDirection,
        colorMode: COLOR_MODES.includes(merged.colorMode)
            ? merged.colorMode
            : DEFAULT_SETTINGS.colorMode,
        nodeScale: Number.isFinite(merged.nodeScale)
            ? Math.min(
                  NODE_SCALE_MAX,
                  Math.max(NODE_SCALE_MIN, merged.nodeScale),
              )
            : DEFAULT_SETTINGS.nodeScale,
    };
}

export function loadSettings(): AppSettings {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);

        if (!raw) {
            return { ...DEFAULT_SETTINGS };
        }

        return sanitize(JSON.parse(raw));
    } catch {
        return { ...DEFAULT_SETTINGS };
    }
}

export function saveSettings(settings: AppSettings): void {
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(settings));
    } catch {
        // Приватный режим / переполненное хранилище — настройки просто не переживут перезагрузку.
    }
}

/** Прокидывает тему, язык и направление письма на <html>. */
export function applyTheme(settings: AppSettings): void {
    const root = document.documentElement;
    root.dataset.theme = settings.theme;
    root.lang = settings.lang;
    root.dir = RTL_LANGUAGES.includes(settings.lang) ? 'rtl' : 'ltr';
}

export type TranslationKeys = {
    currentSpace: string;
    switchSpace: string;
    searchPlaceholder: string;
    nodes: string;
    edges: string;
    undoDelete: string;
    autoOrganize: string;
    addRootNode: string;
    addUserTitle: string;
    newUserHint: string;
    displayNameLabel: string;
    userEmailLabel: string;
    createUserAction: string;
    resetPasswordAction: string;
    activityLogAction: string;
    activityLogTitle: string;
    activityLogDesc: string;
    activityLogEmpty: string;
    activityLogSystemActor: string;
    actionUserCreated: string;
    actionUserDeleted: string;
    actionPasswordReset: string;
    actionSpaceDeleted: string;
    spaceActivityLogAction: string;
    spaceActivityLogTitle: string;
    spaceActivityLogDesc: string;
    spaceFeedNodeCreated: string;
    spaceFeedNodeDeleted: string;
    spaceFeedNodeDeletedCascade: string;
    spaceFeedStructureMode: string;
    spaceFeedLinked: string;
    spaceFeedUnlinked: string;
    spaceFeedCollaboratorAdded: string;
    spaceFeedCollaboratorRemoved: string;
    spaceFeedCollaboratorRoleChanged: string;
    notificationsAction: string;
    notificationsTitle: string;
    notificationsEmpty: string;
    notificationsMarkAllRead: string;
    notificationAccessGranted: string;
    resetPasswordTitle: string;
    newPasswordLabel: string;
    confirmPasswordLabel: string;
    passwordTooShort: string;
    passwordsDontMatch: string;
    genericErrorPrefix: string;
    settingsTitle: string;
    settingsDesc: string;
    tabAppearance: string;
    tabSpace: string;
    tabInterface: string;
    tabStructure: string;
    loginTitle: string;
    loginSubtitle: string;
    usernameLabel: string;
    passwordLabel: string;
    signIn: string;
    signingIn: string;
    signOut: string;
    rememberMe: string;
    invalidCredentials: string;
    tooManyAttempts: string;
    structureLabel: string;
    structureHint: string;
    structureTree: string;
    structureTreeDesc: string;
    structureDag: string;
    structureDagDesc: string;
    structureNetwork: string;
    structureNetworkDesc: string;
    structureLeveled: string;
    structureLeveledDesc: string;
    structureErrSingleParent: string;
    structureErrCycle: string;
    linkErrSelf: string;
    linkErrSingleParent: string;
    linkErrCycle: string;
    linkErrLevelGap: string;
    structureErrLevelGap: string;
    undoExpired: string;
    cancel: string;
    addChildTitle: string;
    parentLabel: string;
    newRootHint: string;
    titleLabel: string;
    descriptionLabel: string;
    nodeTitlePlaceholder: string;
    nodeDetailsPlaceholder: string;
    colorLabel: string;
    customColorLabel: string;
    colorAuto: string;
    tagsLabel: string;
    tagsPlaceholder: string;
    addNode: string;
    editNodeTitle: string;
    saveChanges: string;
    coordinatesLabel: string;
    parentsLabel: string;
    childrenLabel: string;
    openInMaps: string;
    mapSectionLabel: string;
    latLabel: string;
    lonLabel: string;
    mapTitleLabel: string;
    positionSectionLabel: string;
    posXLabel: string;
    posYLabel: string;
    rootPositionHint: string;
    structureSectionLabel: string;
    viewStructureAction: string;
    nodeHistoryAction: string;
    globalSearchPlaceholder: string;
    globalSearchEmpty: string;
    globalSearchHint: string;
    globalSearchAction: string;
    nodeHistoryTitle: string;
    nodeHistoryDesc: string;
    nodeHistoryEmpty: string;
    restoreVersionAction: string;
    restoredNoticeLabel: string;
    commentsAction: string;
    commentsTitle: string;
    commentsEmpty: string;
    commentsPlaceholder: string;
    postCommentAction: string;
    deleteCommentAction: string;
    deletedAuthorLabel: string;
    structureEmptyLabel: string;
    shapeSectionLabel: string;
    logoSectionLabel: string;
    uploadLogoAction: string;
    shapeCircle: string;
    shapeSquare: string;
    shapeTriangle: string;
    shapeDiamond: string;
    shapeHexagon: string;
    treeSettingsAction: string;
    treeSettingsTitle: string;
    treeSettingsHint: string;
    defaultShapeLabel: string;
    defaultColorLabel: string;
    defaultColorNone: string;
    saveDefaultsAction: string;
    applyToAllAction: string;
    applyToAllHint: string;
    uploadFile: string;
    addLink: string;
    linkUrlPlaceholder: string;
    removeAttachment: string;
    uploadFailed: string;
    pendingUploads: string;
    attachmentsLabel: string;
    showAllLabel: string;
    collapseLabel: string;
    previewAction: string;
    download: string;
    editAction: string;
    saveAction: string;
    cancelAction: string;
    savingAction: string;
    previewErrorLabel: string;
    addChild: string;
    linkNode: string;
    editNode: string;
    deleteAction: string;
    copyNode: string;
    copyNodeTitle: string;
    copyNodeSubtitle: string;
    copyTargetLabel: string;
    copyNewRootOption: string;
    copyAction: string;
    linkNodesTitle: string;
    linkNodesSubtitle: string;
    linkDirectionLabel: string;
    linkAsChild: string;
    linkAsParent: string;
    selectTargetLabel: string;
    chooseNodePlaceholder: string;
    createLink: string;
    spacesTitle: string;
    adminSpaceBadge: string;
    roleEditorBadge: string;
    roleViewerBadge: string;
    sharedByPrefix: string;
    shareSpaceAction: string;
    shareSpaceTitle: string;
    shareSpaceDesc: string;
    shareIdentifierLabel: string;
    shareIdentifierPlaceholder: string;
    shareRoleLabel: string;
    shareAction: string;
    shareEmptyLabel: string;
    revokeAccessAction: string;
    ownerLabel: string;
    spacesSubtitle: string;
    deleteSpaceTitle: string;
    spaceNameLabel: string;
    spaceNamePlaceholder: string;
    spaceDescLabel: string;
    spaceDescPlaceholder: string;
    newSpace: string;
    exportSpace: string;
    importSpace: string;
    importBadFormat: string;
    createSpace: string;
    deleteNodeTitle: string;
    calculatingAffected: string;
    deleteConfirmQuestion: string;
    cascadeNoticeTitle: string;
    cascadeNoticeBody: string;
    totalToDelete: string;
    confirmDelete: string;
    undoHint: string;
    languageLabel: string;
    themeLabel: string;
    layoutLabel: string;
    visualsLabel: string;
    interfaceLabel: string;
    curvedEdgesLabel: string;
    showMinimapLabel: string;
    showGridLabel: string;
    showAxesLabel: string;
    showNodeLabelsLabel: string;
    showStatsLabel: string;
    nodeScaleLabel: string;
    reduceMotionLabel: string;
    shortcutsLabel: string;
    shortcutSearch: string;
    shortcutGlobalSearch: string;
    shortcutAddNode: string;
    shortcutEdit: string;
    shortcutLink: string;
    shortcutDelete: string;
    shortcutUndo: string;
    shortcutAutoLayout: string;
    shortcutClose: string;
    compactHudLabel: string;
    resetSettings: string;
    close: string;
    filterTitle: string;
    maxDepthLabel: string;
    maxDepthPlaceholder: string;
    tagContainsLabel: string;
    tagPlaceholder: string;
    leavesOnlyLabel: string;
    createdRangeLabel: string;
    createdFromLabel: string;
    createdToLabel: string;
    untitledNode: string;
    noDescription: string;
    depthLabel: string;
    minimapLabel: string;
    layoutHierarchy: string;
    layoutHierarchyDesc: string;
    directionLabel: string;
    dirDown: string;
    dirUp: string;
    dirRight: string;
    dirLeft: string;
    colorModeLabel: string;
    colorByTree: string;
    colorByDepth: string;
    layoutSpiral: string;
    layoutRings: string;
    layoutGrid: string;
    layoutRadial: string;
    layoutLayered: string;
    layoutCluster: string;
    layoutForce: string;
    layoutSpiralDesc: string;
    layoutRingsDesc: string;
    layoutGridDesc: string;
    layoutRadialDesc: string;
    layoutLayeredDesc: string;
    layoutClusterDesc: string;
    layoutForceDesc: string;
    themeCosmic: string;
    themeMidnight: string;
    themeCyberpunk: string;
    themeLight: string;
};

export const translations: Record<Language, TranslationKeys> = {
    en: {
        currentSpace: 'Current Space',
        switchSpace: 'Switch',
        searchPlaceholder: 'Search nodes by title, description or tag...',
        nodes: 'Nodes',
        edges: 'Edges',
        undoDelete: 'Undo Delete',
        autoOrganize: 'Auto-Organize',
        addRootNode: 'Add Root Node',
        addUserTitle: 'Add User',
        newUserHint: 'Creates a login for a new user of this system',
        displayNameLabel: 'Display name',
        userEmailLabel: 'Email',
        createUserAction: 'Create User',
        resetPasswordAction: 'Reset Password',
        activityLogAction: 'Activity Log',
        activityLogTitle: 'Activity Log',
        activityLogDesc: 'Recent administrator actions',
        activityLogEmpty: 'No activity yet.',
        activityLogSystemActor: 'System',
        actionUserCreated: 'created user {name}',
        actionUserDeleted: 'deleted user {name}',
        actionPasswordReset: 'reset the password for {name}',
        actionSpaceDeleted: 'deleted space {name}',
        spaceActivityLogAction: 'Space Activity',
        spaceActivityLogTitle: 'Space activity',
        spaceActivityLogDesc: 'Recent changes in this space',
        spaceFeedNodeCreated: 'created node {title}',
        spaceFeedNodeDeleted: 'deleted node {titles}',
        spaceFeedNodeDeletedCascade:
            'deleted {titles} along with its descendants ({count} nodes total)',
        spaceFeedStructureMode: 'changed the space structure to {structure}',
        spaceFeedLinked: 'linked {child} under {parent}',
        spaceFeedUnlinked: 'unlinked {child} from {parent}',
        spaceFeedCollaboratorAdded: 'gave {name} {role} access',
        spaceFeedCollaboratorRemoved: "removed {name}'s access",
        spaceFeedCollaboratorRoleChanged: "changed {name}'s role to {role}",
        notificationsAction: 'Notifications',
        notificationsTitle: 'Notifications',
        notificationsEmpty: "You're all caught up.",
        notificationsMarkAllRead: 'Mark all as read',
        notificationAccessGranted:
            '{owner} gave you {role} access to "{space}"',
        resetPasswordTitle: 'Reset password',
        newPasswordLabel: 'New password (min. 8 characters)',
        confirmPasswordLabel: 'Confirm password',
        passwordTooShort: 'Password must be at least 8 characters.',
        passwordsDontMatch: 'Passwords do not match.',
        genericErrorPrefix: 'Error: {error}',
        settingsTitle: 'System Settings',
        settingsDesc: 'Language, theme, node layout and visual effects',
        tabAppearance: 'Appearance',
        tabSpace: 'Scene',
        tabInterface: 'Interface',
        tabStructure: 'Structure',
        loginTitle: 'Sign In',
        loginSubtitle: 'Enter your credentials to open the space',
        usernameLabel: 'Login',
        passwordLabel: 'Password',
        signIn: 'Sign In',
        signingIn: 'Signing in...',
        signOut: 'Sign out',
        rememberMe: 'Stay signed in',
        invalidCredentials: 'Wrong login or password.',
        tooManyAttempts: 'Too many attempts. Please wait a minute.',
        structureLabel: 'Space Structure',
        structureHint:
            'Applies to the current space only — your other spaces keep their own structure.',
        structureTree: 'Strict Tree',
        structureTreeDesc:
            'Every node has at most one parent. Cycles forbidden.',
        structureDag: 'Acyclic Graph (DAG)',
        structureDagDesc:
            'A node may have several parents. Cycles still forbidden.',
        structureNetwork: 'Free Network',
        structureLeveled: 'Leveled Graph',
        structureLeveledDesc:
            'Every link goes exactly one level down. Cycles impossible by construction.',
        structureNetworkDesc:
            'Cycles allowed, no roots. Deleting removes only the selected node.',
        structureErrSingleParent:
            'Cannot switch to a tree: {n} node(s) have more than one parent.',
        structureErrCycle: 'Cannot switch: the graph already contains a cycle.',
        linkErrSelf: 'A node cannot be linked to itself.',
        linkErrSingleParent:
            'This space is a strict tree — the target node already has a parent.',
        linkErrCycle: 'This link would create a cycle.',
        linkErrLevelGap:
            'This space is leveled — the link would span more than one level.',
        structureErrLevelGap:
            'Cannot switch to a leveled graph: {n} link(s) span more than one level.',
        undoExpired: 'Nothing to restore — the undo snapshot has expired.',
        cancel: 'Cancel',
        addChildTitle: 'Add Child Node',
        parentLabel: 'Parent',
        newRootHint: 'New independent tree root',
        titleLabel: 'Title',
        descriptionLabel: 'Description',
        nodeTitlePlaceholder: 'Node title',
        nodeDetailsPlaceholder: 'Node details or notes...',
        colorLabel: 'Color',
        customColorLabel: 'Custom Color',
        colorAuto: 'Auto',
        tagsLabel: 'Tags (comma-separated)',
        tagsPlaceholder: 'e.g. core, feature',
        addNode: 'Add Node',
        editNodeTitle: 'Edit Node',
        saveChanges: 'Save Changes',
        coordinatesLabel: 'Coordinates',
        parentsLabel: 'Parents',
        childrenLabel: 'Children',
        openInMaps: 'Open in Maps',
        mapSectionLabel: 'Map point',
        latLabel: 'Latitude',
        lonLabel: 'Longitude',
        mapTitleLabel: 'Map caption',
        positionSectionLabel: 'Position in space',
        posXLabel: 'X',
        posYLabel: 'Y',
        rootPositionHint:
            'Root nodes only — children are placed automatically.',
        structureSectionLabel: 'Structure',
        viewStructureAction: 'View structure',
        nodeHistoryAction: 'History',
        globalSearchPlaceholder: 'Search across all your spaces...',
        globalSearchEmpty: 'No matches.',
        globalSearchHint: 'Type at least 2 characters to search everywhere.',
        globalSearchAction: 'Search everywhere',
        nodeHistoryTitle: 'Edit history',
        nodeHistoryDesc: 'Snapshots taken before each change',
        nodeHistoryEmpty: 'No edits yet.',
        restoreVersionAction: 'Restore this version',
        restoredNoticeLabel: 'Restored',
        commentsAction: 'Comments',
        commentsTitle: 'Comments',
        commentsEmpty: 'No comments yet.',
        commentsPlaceholder: 'Write a comment...',
        postCommentAction: 'Post',
        deleteCommentAction: 'Delete comment',
        deletedAuthorLabel: 'Deleted user',
        structureEmptyLabel: 'This space is empty.',
        shapeSectionLabel: 'Shape',
        logoSectionLabel: 'Logo',
        uploadLogoAction: 'Upload logo',
        shapeCircle: 'Circle',
        shapeSquare: 'Square',
        shapeTriangle: 'Triangle',
        shapeDiamond: 'Diamond',
        shapeHexagon: 'Hexagon',
        treeSettingsAction: 'Tree settings',
        treeSettingsTitle: 'Tree settings',
        treeSettingsHint:
            'New child nodes added anywhere in this tree will use this shape and color by default.',
        defaultShapeLabel: 'Default shape',
        defaultColorLabel: 'Default color',
        defaultColorNone: 'None',
        saveDefaultsAction: 'Save defaults',
        applyToAllAction: 'Apply to all nodes in this tree',
        applyToAllHint:
            'Overwrites the shape and color of every existing node in this tree, including the root.',
        uploadFile: 'Upload file',
        addLink: 'Add link',
        linkUrlPlaceholder: 'https://...',
        removeAttachment: 'Remove',
        uploadFailed: 'Could not upload the file.',
        pendingUploads: 'Will be attached after saving',
        attachmentsLabel: 'Files and links',
        showAllLabel: 'Show all',
        collapseLabel: 'Collapse',
        previewAction: 'Preview',
        download: 'Download',
        editAction: 'Edit',
        saveAction: 'Save',
        cancelAction: 'Cancel',
        savingAction: 'Saving…',
        previewErrorLabel: 'Could not load the file',
        addChild: 'Add Child',
        linkNode: 'Link Node',
        editNode: 'Edit Node',
        deleteAction: 'Delete',
        copyNode: 'Copy',
        copyNodeTitle: 'Copy Node',
        copyNodeSubtitle: 'Copy {name} together with its subtree',
        copyTargetLabel: 'Attach the copy under',
        copyNewRootOption: '— New independent root —',
        copyAction: 'Copy',
        linkNodesTitle: 'Link Nodes',
        linkNodesSubtitle: 'Connect {name} to another node',
        linkDirectionLabel: 'Link Direction',
        linkAsChild: 'Target becomes child',
        linkAsParent: 'Target becomes parent',
        selectTargetLabel: 'Select Target Node',
        chooseNodePlaceholder: 'Choose a node...',
        createLink: 'Create Link',
        spacesTitle: 'Spaces',
        adminSpaceBadge: 'Admin',
        roleEditorBadge: 'Editor',
        roleViewerBadge: 'Viewer',
        sharedByPrefix: 'Shared by',
        shareSpaceAction: 'Share',
        shareSpaceTitle: 'Share space',
        shareSpaceDesc: 'Give another user viewer or editor access',
        shareIdentifierLabel: 'Username or email',
        shareIdentifierPlaceholder: 'e.g. alice or alice@example.com',
        shareRoleLabel: 'Role',
        shareAction: 'Share',
        shareEmptyLabel: 'No one else has access yet.',
        revokeAccessAction: 'Revoke access',
        ownerLabel: 'Owner',
        spacesSubtitle: 'Manage and switch between your graph spaces',
        deleteSpaceTitle: 'Delete Space',
        spaceNameLabel: 'Space Name',
        spaceNamePlaceholder: 'e.g. Brainstorming Space',
        spaceDescLabel: 'Description (optional)',
        spaceDescPlaceholder: 'Describe the purpose of this space...',
        newSpace: 'New Space',
        exportSpace: 'Export JSON',
        importSpace: 'Import JSON',
        importBadFormat: 'This file is not a valid space export.',
        createSpace: 'Create Space',
        deleteNodeTitle: 'Delete Node',
        calculatingAffected: 'Calculating affected nodes...',
        deleteConfirmQuestion: 'Are you sure you want to delete {title}?',
        cascadeNoticeTitle: 'Cascade Subtree Deletion Notice',
        cascadeNoticeBody:
            'Deleting this node will also remove {n} unreachable descendant node(s).',
        totalToDelete: 'Total nodes to be deleted: {n}',
        confirmDelete: 'Confirm Delete',
        undoHint: 'You can undo this right after deleting.',
        languageLabel: 'Interface Language',
        themeLabel: 'Color Theme',
        layoutLabel: 'Node Layout',
        visualsLabel: 'Visual Effects',
        interfaceLabel: 'Interface',
        curvedEdgesLabel: 'Curved Bezier Links',
        showMinimapLabel: 'Show Minimap',
        showGridLabel: 'Background Grid',
        showAxesLabel: 'Coordinate Axes',
        showNodeLabelsLabel: 'Node Name Labels',
        showStatsLabel: 'Show Statistics',
        nodeScaleLabel: 'Node Size',
        reduceMotionLabel: 'Reduce Motion',
        shortcutsLabel: 'Keyboard shortcuts',
        shortcutSearch: 'Focus search',
        shortcutGlobalSearch: 'Search across all spaces',
        shortcutAddNode: 'Add child (root if nothing selected)',
        shortcutEdit: 'Edit selected node',
        shortcutLink: 'Link selected node',
        shortcutDelete: 'Delete selected node',
        shortcutUndo: 'Undo last delete',
        shortcutAutoLayout: 'Auto-organize layout',
        shortcutClose: 'Close dialog / deselect',
        compactHudLabel: 'Compact Toolbar',
        resetSettings: 'Reset to Defaults',
        close: 'Close',
        filterTitle: 'Node Filters',
        maxDepthLabel: 'Max Depth',
        maxDepthPlaceholder: 'No depth limit',
        tagContainsLabel: 'Tag Contains',
        tagPlaceholder: 'e.g. origin',
        leavesOnlyLabel: 'Leaves only (no children)',
        createdRangeLabel: 'Created between',
        createdFromLabel: 'From',
        createdToLabel: 'To',
        untitledNode: 'Untitled Node',
        noDescription: 'No description',
        depthLabel: 'Depth',
        minimapLabel: 'MINIMAP',
        layoutHierarchy: 'Hierarchy Tree',
        layoutHierarchyDesc:
            'Tidy tree: children packed under their own parent, level by level',
        directionLabel: 'Direction',
        dirDown: 'Top to bottom',
        dirUp: 'Bottom to top',
        dirRight: 'Left to right',
        dirLeft: 'Right to left',
        colorModeLabel: 'Node Coloring',
        colorByTree: 'By tree',
        colorByDepth: 'By level',
        layoutSpiral: 'Golden Spiral',
        layoutRings: 'Concentric Rings',
        layoutGrid: 'Grid',
        layoutRadial: 'Radial Tree',
        layoutLayered: 'Layered DAG',
        layoutCluster: 'Cluster Constellations',
        layoutForce: 'Force-Directed Graph',
        layoutSpiralDesc: 'Fibonacci spiral around the centre',
        layoutRingsDesc: 'Each level is a ring around the centre',
        layoutGridDesc: 'Even rows and columns',
        layoutRadialDesc: 'Tree rings: depth as radius from root',
        layoutLayeredDesc: 'Horizontal layers by graph depth (Sugiyama-style)',
        layoutClusterDesc: 'Separate clouds per tree root / tag group',
        layoutForceDesc: 'Physics simulation: repulsion + edge attraction',
        themeCosmic: 'Cosmic Dark',
        themeMidnight: 'Midnight Blue',
        themeCyberpunk: 'Cyberpunk Violet',
        themeLight: 'Light Clean',
    },
    ru: {
        currentSpace: 'Текущее пространство',
        switchSpace: 'Сменить',
        searchPlaceholder: 'Поиск узлов по названию, описанию или тегам...',
        nodes: 'Узлов',
        edges: 'Связей',
        undoDelete: 'Отменить удаление',
        autoOrganize: 'Авто-расстановка',
        addRootNode: 'Добавить корень',
        addUserTitle: 'Добавить пользователя',
        newUserHint: 'Создаёт вход для нового пользователя системы',
        displayNameLabel: 'Отображаемое имя',
        userEmailLabel: 'Email',
        createUserAction: 'Создать пользователя',
        resetPasswordAction: 'Сбросить пароль',
        activityLogAction: 'Журнал действий',
        activityLogTitle: 'Журнал действий',
        activityLogDesc: 'Последние действия администратора',
        activityLogEmpty: 'Пока пусто.',
        activityLogSystemActor: 'Система',
        actionUserCreated: 'создал пользователя {name}',
        actionUserDeleted: 'удалил пользователя {name}',
        actionPasswordReset: 'сбросил пароль пользователю {name}',
        actionSpaceDeleted: 'удалил пространство {name}',
        spaceActivityLogAction: 'Лента изменений',
        spaceActivityLogTitle: 'Изменения пространства',
        spaceActivityLogDesc: 'Последние изменения в этом пространстве',
        spaceFeedNodeCreated: 'создал узел {title}',
        spaceFeedNodeDeleted: 'удалил узел {titles}',
        spaceFeedNodeDeletedCascade:
            'удалил {titles} вместе с потомками ({count} узлов всего)',
        spaceFeedStructureMode:
            'изменил структуру пространства на «{structure}»',
        spaceFeedLinked: 'связал «{child}» с «{parent}»',
        spaceFeedUnlinked: 'разорвал связь «{child}» с «{parent}»',
        spaceFeedCollaboratorAdded: 'дал «{name}» доступ ({role})',
        spaceFeedCollaboratorRemoved: 'забрал доступ у «{name}»',
        spaceFeedCollaboratorRoleChanged: 'изменил роль «{name}» на «{role}»',
        notificationsAction: 'Уведомления',
        notificationsTitle: 'Уведомления',
        notificationsEmpty: 'Новых уведомлений нет.',
        notificationsMarkAllRead: 'Отметить все прочитанными',
        notificationAccessGranted:
            '{owner} открыл(а) вам доступ ({role}) к «{space}»',
        resetPasswordTitle: 'Сброс пароля',
        newPasswordLabel: 'Новый пароль (минимум 8 символов)',
        confirmPasswordLabel: 'Повторите пароль',
        passwordTooShort: 'Пароль должен быть не короче 8 символов.',
        passwordsDontMatch: 'Пароли не совпадают.',
        genericErrorPrefix: 'Ошибка: {error}',
        settingsTitle: 'Настройки системы',
        settingsDesc: 'Язык, тема, расстановка узлов и визуальные эффекты',
        tabAppearance: 'Оформление',
        tabSpace: 'Сцена',
        tabInterface: 'Интерфейс',
        tabStructure: 'Структура',
        loginTitle: 'Вход',
        loginSubtitle: 'Введите данные, чтобы открыть пространство',
        usernameLabel: 'Логин',
        passwordLabel: 'Пароль',
        signIn: 'Войти',
        signingIn: 'Вход...',
        signOut: 'Выйти',
        rememberMe: 'Не выходить',
        invalidCredentials: 'Неверный логин или пароль.',
        tooManyAttempts: 'Слишком много попыток. Подождите минуту.',
        structureLabel: 'Структура пространства',
        structureHint:
            'Относится только к текущему пространству — у остальных своя структура.',
        structureTree: 'Строгое дерево',
        structureTreeDesc:
            'У каждого узла не больше одного родителя. Циклы запрещены.',
        structureDag: 'Ациклический граф (DAG)',
        structureDagDesc:
            'У узла может быть несколько родителей. Циклы по-прежнему запрещены.',
        structureNetwork: 'Свободная сеть',
        structureLeveled: 'Уровневый граф',
        structureLeveledDesc:
            'Каждая связь ведёт ровно на один уровень вниз. Циклы невозможны по построению.',
        structureNetworkDesc:
            'Циклы разрешены, корней нет. Удаление затрагивает только выбранный узел.',
        structureErrSingleParent:
            'Нельзя перейти к дереву: у {n} узл. больше одного родителя.',
        structureErrCycle: 'Нельзя перейти: в графе уже есть цикл.',
        linkErrSelf: 'Узел нельзя связать сам с собой.',
        linkErrSingleParent:
            'Это пространство — строгое дерево, у выбранного узла уже есть родитель.',
        linkErrCycle: 'Такая связь создаст цикл.',
        linkErrLevelGap:
            'Это уровневое пространство — связь перепрыгнула бы через уровень.',
        structureErrLevelGap:
            'Нельзя перейти к уровневому графу: {n} связ. перепрыгивают уровень.',
        undoExpired: 'Нечего восстанавливать — снимок для отмены устарел.',
        cancel: 'Отмена',
        addChildTitle: 'Добавить потомка',
        parentLabel: 'Родитель',
        newRootHint: 'Новый независимый корень дерева',
        titleLabel: 'Название',
        descriptionLabel: 'Описание',
        nodeTitlePlaceholder: 'Название узла',
        nodeDetailsPlaceholder: 'Подробности или заметки...',
        colorLabel: 'Цвет',
        customColorLabel: 'Свой цвет',
        colorAuto: 'Авто',
        tagsLabel: 'Теги (через запятую)',
        tagsPlaceholder: 'напр. core, feature',
        addNode: 'Добавить узел',
        editNodeTitle: 'Редактировать узел',
        saveChanges: 'Сохранить',
        coordinatesLabel: 'Координаты',
        parentsLabel: 'Родители',
        childrenLabel: 'Дети',
        openInMaps: 'Открыть на карте',
        mapSectionLabel: 'Точка на карте',
        latLabel: 'Широта',
        lonLabel: 'Долгота',
        mapTitleLabel: 'Подпись к карте',
        positionSectionLabel: 'Позиция в пространстве',
        posXLabel: 'X',
        posYLabel: 'Y',
        rootPositionHint:
            'Только для корневых узлов — дочерние размещаются автоматически.',
        structureSectionLabel: 'Структура',
        viewStructureAction: 'Смотреть структуру',
        nodeHistoryAction: 'История',
        globalSearchPlaceholder: 'Поиск по всем вашим пространствам...',
        globalSearchEmpty: 'Ничего не найдено.',
        globalSearchHint: 'Введите минимум 2 символа для поиска везде.',
        globalSearchAction: 'Искать везде',
        nodeHistoryTitle: 'История изменений',
        nodeHistoryDesc: 'Снимки состояния перед каждой правкой',
        nodeHistoryEmpty: 'Правок пока не было.',
        restoreVersionAction: 'Восстановить эту версию',
        restoredNoticeLabel: 'Восстановлено',
        commentsAction: 'Комментарии',
        commentsTitle: 'Комментарии',
        commentsEmpty: 'Пока нет комментариев.',
        commentsPlaceholder: 'Написать комментарий...',
        postCommentAction: 'Отправить',
        deleteCommentAction: 'Удалить комментарий',
        deletedAuthorLabel: 'Удалённый пользователь',
        structureEmptyLabel: 'Это пространство пустое.',
        shapeSectionLabel: 'Форма',
        logoSectionLabel: 'Логотип',
        uploadLogoAction: 'Загрузить логотип',
        shapeCircle: 'Круг',
        shapeSquare: 'Квадрат',
        shapeTriangle: 'Треугольник',
        shapeDiamond: 'Ромб',
        shapeHexagon: 'Шестиугольник',
        treeSettingsAction: 'Настройки дерева',
        treeSettingsTitle: 'Настройки дерева',
        treeSettingsHint:
            'Новые дочерние узлы в любом месте этого дерева будут по умолчанию получать эту форму и цвет.',
        defaultShapeLabel: 'Форма по умолчанию',
        defaultColorLabel: 'Цвет по умолчанию',
        defaultColorNone: 'Нет',
        saveDefaultsAction: 'Сохранить значения по умолчанию',
        applyToAllAction: 'Применить ко всем узлам дерева',
        applyToAllHint:
            'Перезапишет форму и цвет у всех существующих узлов этого дерева, включая корневой.',
        uploadFile: 'Загрузить файл',
        addLink: 'Добавить ссылку',
        linkUrlPlaceholder: 'https://...',
        removeAttachment: 'Убрать',
        uploadFailed: 'Не удалось загрузить файл.',
        pendingUploads: 'Прикрепятся после сохранения',
        attachmentsLabel: 'Файлы и ссылки',
        showAllLabel: 'Показать все',
        collapseLabel: 'Свернуть',
        previewAction: 'Просмотр',
        download: 'Скачать',
        editAction: 'Редактировать',
        saveAction: 'Сохранить',
        cancelAction: 'Отмена',
        savingAction: 'Сохранение…',
        previewErrorLabel: 'Не удалось загрузить файл',
        addChild: 'Добавить потомка',
        linkNode: 'Связать',
        editNode: 'Изменить',
        deleteAction: 'Удалить',
        copyNode: 'Копировать',
        copyNodeTitle: 'Копирование узла',
        copyNodeSubtitle: 'Скопировать «{name}» вместе со всем поддеревом',
        copyTargetLabel: 'Прикрепить копию к',
        copyNewRootOption: '— Новый независимый корень —',
        copyAction: 'Копировать',
        linkNodesTitle: 'Связывание узлов',
        linkNodesSubtitle: 'Соединить «{name}» с другим узлом',
        linkDirectionLabel: 'Направление связи',
        linkAsChild: 'Цель станет потомком',
        linkAsParent: 'Цель станет родителем',
        selectTargetLabel: 'Выберите целевой узел',
        chooseNodePlaceholder: 'Выберите узел...',
        createLink: 'Создать связь',
        spacesTitle: 'Пространства',
        adminSpaceBadge: 'Админ',
        roleEditorBadge: 'Редактор',
        roleViewerBadge: 'Просмотр',
        sharedByPrefix: 'Расшарил(а)',
        shareSpaceAction: 'Поделиться',
        shareSpaceTitle: 'Доступ к пространству',
        shareSpaceDesc:
            'Дайте другому пользователю доступ на просмотр или редактирование',
        shareIdentifierLabel: 'Логин или email',
        shareIdentifierPlaceholder: 'например, alice или alice@example.com',
        shareRoleLabel: 'Роль',
        shareAction: 'Открыть доступ',
        shareEmptyLabel: 'Пока ни у кого нет доступа.',
        revokeAccessAction: 'Забрать доступ',
        ownerLabel: 'Владелец',
        spacesSubtitle: 'Управление пространствами и переключение между ними',
        deleteSpaceTitle: 'Удалить пространство',
        spaceNameLabel: 'Название пространства',
        spaceNamePlaceholder: 'напр. Мозговой штурм',
        spaceDescLabel: 'Описание (необязательно)',
        spaceDescPlaceholder: 'Для чего это пространство...',
        newSpace: 'Новое пространство',
        exportSpace: 'Выгрузить JSON',
        importSpace: 'Загрузить JSON',
        importBadFormat: 'Это не похоже на выгрузку пространства.',
        createSpace: 'Создать',
        deleteNodeTitle: 'Удаление узла',
        calculatingAffected: 'Считаем затронутые узлы...',
        deleteConfirmQuestion: 'Точно удалить «{title}»?',
        cascadeNoticeTitle: 'Внимание: каскадное удаление поддерева',
        cascadeNoticeBody:
            'Вместе с этим узлом удалится ещё {n} узл., ставших недостижимыми.',
        totalToDelete: 'Всего узлов к удалению: {n}',
        confirmDelete: 'Удалить',
        undoHint: 'Сразу после удаления действие можно отменить.',
        languageLabel: 'Язык интерфейса',
        themeLabel: 'Тема оформления',
        layoutLabel: 'Режим расстановки узлов',
        visualsLabel: 'Визуальные эффекты',
        interfaceLabel: 'Интерфейс',
        curvedEdgesLabel: 'Дугообразные связи (Безье)',
        showMinimapLabel: 'Показывать мини-карту',
        showGridLabel: 'Сетка на фоне',
        showAxesLabel: 'Оси координат',
        showNodeLabelsLabel: 'Подписи узлов',
        showStatsLabel: 'Показывать статистику',
        nodeScaleLabel: 'Размер узлов',
        reduceMotionLabel: 'Меньше анимации',
        shortcutsLabel: 'Горячие клавиши',
        shortcutSearch: 'Фокус на поиске',
        shortcutGlobalSearch: 'Поиск по всем пространствам',
        shortcutAddNode: 'Добавить потомка (корень, если ничего не выбрано)',
        shortcutEdit: 'Редактировать выбранный узел',
        shortcutLink: 'Связать выбранный узел',
        shortcutDelete: 'Удалить выбранный узел',
        shortcutUndo: 'Отменить последнее удаление',
        shortcutAutoLayout: 'Авто-упорядочивание',
        shortcutClose: 'Закрыть диалог / снять выделение',
        compactHudLabel: 'Компактная панель',
        resetSettings: 'Сбросить настройки',
        close: 'Закрыть',
        filterTitle: 'Фильтры узлов',
        maxDepthLabel: 'Макс. глубина',
        maxDepthPlaceholder: 'Без ограничения',
        tagContainsLabel: 'Тег содержит',
        tagPlaceholder: 'напр. origin',
        leavesOnlyLabel: 'Только листья (без детей)',
        createdRangeLabel: 'Создано в диапазоне',
        createdFromLabel: 'С',
        createdToLabel: 'По',
        untitledNode: 'Без названия',
        noDescription: 'Нет описания',
        depthLabel: 'Глубина',
        minimapLabel: 'МИНИ-КАРТА',
        layoutHierarchy: 'Иерархия (дерево)',
        layoutHierarchyDesc:
            'Аккуратное дерево: дети собраны под своим родителем, уровень за уровнем',
        directionLabel: 'Направление',
        dirDown: 'Сверху вниз',
        dirUp: 'Снизу вверх',
        dirRight: 'Слева направо',
        dirLeft: 'Справа налево',
        colorModeLabel: 'Раскраска узлов',
        colorByTree: 'По дереву',
        colorByDepth: 'По уровню',
        layoutSpiral: 'Золотая спираль',
        layoutRings: 'Концентрические кольца',
        layoutGrid: 'Сетка',
        layoutRadial: 'Радиальное дерево',
        layoutLayered: 'Слоистый DAG',
        layoutCluster: 'Кластеры-созвездия',
        layoutForce: 'Силовой граф',
        layoutSpiralDesc: 'Спираль Фибоначчи вокруг центра',
        layoutRingsDesc: 'Каждый уровень — кольцо вокруг центра',
        layoutGridDesc: 'Ровные строки и столбцы',
        layoutRadialDesc: 'Кольца дерева: глубина как радиус от корня',
        layoutLayeredDesc: 'Горизонтальные слои по глубине графа',
        layoutClusterDesc: 'Отдельные облака для каждого корня / группы тегов',
        layoutForceDesc:
            'Физическая симуляция: отталкивание + притяжение рёбер',
        themeCosmic: 'Космическая тёмная',
        themeMidnight: 'Полуночно-синяя',
        themeCyberpunk: 'Киберпанк (фиолетовая)',
        themeLight: 'Светлая лаконичная',
    },
    tg: {
        currentSpace: 'Фазои ҷорӣ',
        switchSpace: 'Иваз кардан',
        searchPlaceholder: 'Ҷустуҷӯи гузарҳо бо ном, тавсиф ё тег...',
        nodes: 'Гузарҳо',
        edges: 'Пайвандҳо',
        undoDelete: 'Бекор кардани несткунӣ',
        autoOrganize: 'Ҷойгиркунии автоматӣ',
        addRootNode: 'Иловаи реша',
        addUserTitle: 'Иловаи корбар',
        newUserHint: 'Воридшавӣ барои корбари нави система месозад',
        displayNameLabel: 'Номи намоишӣ',
        userEmailLabel: 'Email',
        createUserAction: 'Сохтани корбар',
        resetPasswordAction: 'Бознишонии рамз',
        activityLogAction: 'Рӯзномаи амалҳо',
        activityLogTitle: 'Рӯзномаи амалҳо',
        activityLogDesc: 'Амалҳои охирини маъмур',
        activityLogEmpty: 'Ҳанӯз чизе нест.',
        activityLogSystemActor: 'Система',
        actionUserCreated: 'корбар {name}-ро сохт',
        actionUserDeleted: 'корбар {name}-ро нест кард',
        actionPasswordReset: 'рамзи корбари {name}-ро бознишон кард',
        actionSpaceDeleted: 'фазои {name}-ро нест кард',
        spaceActivityLogAction: 'Рӯзномаи тағйирот',
        spaceActivityLogTitle: 'Тағйироти фазо',
        spaceActivityLogDesc: 'Тағйироти охирин дар ин фазо',
        spaceFeedNodeCreated: 'гиреҳи {title}-ро сохт',
        spaceFeedNodeDeleted: 'гиреҳи {titles}-ро нест кард',
        spaceFeedNodeDeletedCascade:
            '{titles}-ро ҳамроҳи авлодонаш нест кард ({count} гиреҳ ҳамагӣ)',
        spaceFeedStructureMode: 'сохтори фазоро ба «{structure}» иваз кард',
        spaceFeedLinked: '«{child}»-ро бо «{parent}» пайваст кард',
        spaceFeedUnlinked: 'пайванди «{child}»-ро бо «{parent}» кандааст',
        spaceFeedCollaboratorAdded: 'ба «{name}» дастрасии {role} дод',
        spaceFeedCollaboratorRemoved: 'дастрасии «{name}»-ро гирифт',
        spaceFeedCollaboratorRoleChanged:
            'нақши «{name}»-ро ба «{role}» иваз кард',
        notificationsAction: 'Огоҳиномаҳо',
        notificationsTitle: 'Огоҳиномаҳо',
        notificationsEmpty: 'Огоҳиномаи нав нест.',
        notificationsMarkAllRead: 'Ҳамаро хондашуда қайд кардан',
        notificationAccessGranted:
            '{owner} ба шумо дастрасии {role} ба «{space}» дод',
        resetPasswordTitle: 'Бознишонии рамз',
        newPasswordLabel: 'Рамзи нав (на кам аз 8 аломат)',
        confirmPasswordLabel: 'Рамзро такрор кунед',
        passwordTooShort: 'Рамз бояд на кам аз 8 аломат бошад.',
        passwordsDontMatch: 'Рамзҳо мувофиқат намекунанд.',
        genericErrorPrefix: 'Хато: {error}',
        settingsTitle: 'Танзимоти система',
        settingsDesc: 'Забон, мавзӯъ, ҷойгиркунии гузарҳо ва эффектҳо',
        tabAppearance: 'Намуди зоҳирӣ',
        tabSpace: 'Саҳна',
        tabInterface: 'Интерфейс',
        tabStructure: 'Сохтор',
        loginTitle: 'Воридшавӣ',
        loginSubtitle: 'Барои кушодани фазо маълумоти худро ворид кунед',
        usernameLabel: 'Логин',
        passwordLabel: 'Рамз',
        signIn: 'Ворид шудан',
        signingIn: 'Воридшавӣ...',
        signOut: 'Баромадан',
        rememberMe: 'Дар система мондан',
        invalidCredentials: 'Логин ё рамз нодуруст.',
        tooManyAttempts: 'Кӯшишҳо аз ҳад зиёд. Як дақиқа интизор шавед.',
        structureLabel: 'Сохтори фазо',
        structureHint:
            'Танҳо ба фазои ҷорӣ дахл дорад — дигар фазоҳо сохтори худро нигоҳ медоранд.',
        structureTree: 'Дарахти қатъӣ',
        structureTreeDesc:
            'Ҳар гузар на бештар аз як волид дорад. Давраҳо манъ.',
        structureDag: 'Графи бедавра (DAG)',
        structureDagDesc:
            'Гузар метавонад чанд волид дошта бошад. Давраҳо ҳанӯз манъ.',
        structureNetwork: 'Шабакаи озод',
        structureLeveled: 'Графи зинагӣ',
        structureLeveledDesc:
            'Ҳар пайванд маҳз як зина поён меравад. Давраҳо ғайриимкон.',
        structureNetworkDesc:
            'Давраҳо иҷозат, реша нест. Несткунӣ танҳо гузари интихобшударо мебарорад.',
        structureErrSingleParent:
            'Гузариш ба дарахт мумкин нест: {n} гузар зиёда аз як волид дорад.',
        structureErrCycle: 'Гузариш мумкин нест: дар граф аллакай давра ҳаст.',
        linkErrSelf: 'Гузарро бо худаш пайваст кардан мумкин нест.',
        linkErrSingleParent:
            'Ин фазо дарахти қатъист — гузари интихобшуда аллакай волид дорад.',
        linkErrCycle: 'Ин пайванд давра эҷод мекунад.',
        linkErrLevelGap: 'Ин фазо зинагӣ аст — пайванд аз зина мепарид.',
        structureErrLevelGap:
            'Гузариш ба графи зинагӣ мумкин нест: {n} пайванд аз зина мепарад.',
        undoExpired: 'Барои барқарорсозӣ чизе нест — снимок кӯҳна шудааст.',
        cancel: 'Бекор',
        addChildTitle: 'Иловаи фарзанд',
        parentLabel: 'Волид',
        newRootHint: 'Решаи нави мустақил',
        titleLabel: 'Ном',
        descriptionLabel: 'Тавсиф',
        nodeTitlePlaceholder: 'Номи гузар',
        nodeDetailsPlaceholder: 'Тафсилот ё қайдҳо...',
        colorLabel: 'Ранг',
        customColorLabel: 'Ранги дилхоҳ',
        colorAuto: 'Худкор',
        tagsLabel: 'Тегҳо (бо вергул)',
        tagsPlaceholder: 'мас. core, feature',
        addNode: 'Илова кардан',
        editNodeTitle: 'Таҳрири гузар',
        saveChanges: 'Нигоҳ доштан',
        coordinatesLabel: 'Координатаҳо',
        parentsLabel: 'Волидон',
        childrenLabel: 'Фарзандон',
        openInMaps: 'Дар харита кушодан',
        mapSectionLabel: 'Нуқта дар харита',
        latLabel: 'Арз',
        lonLabel: 'Тӯл',
        mapTitleLabel: 'Имзои харита',
        positionSectionLabel: 'Мавқеъ дар фазо',
        posXLabel: 'X',
        posYLabel: 'Y',
        rootPositionHint:
            'Танҳо барои гузарҳои реша — фарзандон худкор ҷойгир мешаванд.',
        structureSectionLabel: 'Сохтор',
        viewStructureAction: 'Дидани сохтор',
        nodeHistoryAction: 'Таърих',
        globalSearchPlaceholder: 'Ҷустуҷӯ дар ҳамаи фазоҳои шумо...',
        globalSearchEmpty: 'Чизе ёфт нашуд.',
        globalSearchHint:
            'Барои ҷустуҷӯ дар ҳама ҷо на кам аз 2 аломат ворид кунед.',
        globalSearchAction: 'Ҷустуҷӯ дар ҳама ҷо',
        nodeHistoryTitle: 'Таърихи тағйирот',
        nodeHistoryDesc: 'Аксҳо пеш аз ҳар тағйирот',
        nodeHistoryEmpty: 'Ҳанӯз тағйирот набудааст.',
        restoreVersionAction: 'Барқарор кардани ин версия',
        restoredNoticeLabel: 'Барқарор карда шуд',
        commentsAction: 'Шарҳҳо',
        commentsTitle: 'Шарҳҳо',
        commentsEmpty: 'Ҳанӯз шарҳе нест.',
        commentsPlaceholder: 'Шарҳ нависед...',
        postCommentAction: 'Фиристодан',
        deleteCommentAction: 'Нест кардани шарҳ',
        deletedAuthorLabel: 'Корбари ҳазфшуда',
        structureEmptyLabel: 'Ин фазо холӣ аст.',
        shapeSectionLabel: 'Шакл',
        logoSectionLabel: 'Логотип',
        uploadLogoAction: 'Боркунии логотип',
        shapeCircle: 'Доира',
        shapeSquare: 'Мураббаъ',
        shapeTriangle: 'Секунҷа',
        shapeDiamond: 'Ромб',
        shapeHexagon: 'Шашкунҷа',
        treeSettingsAction: 'Танзимоти дарахт',
        treeSettingsTitle: 'Танзимоти дарахт',
        treeSettingsHint:
            'Гиреҳҳои фарзандии нав дар ҳар қисми ин дарахт ин шакл ва рангро ба таври пешфарз мегиранд.',
        defaultShapeLabel: 'Шакли пешфарз',
        defaultColorLabel: 'Ранги пешфарз',
        defaultColorNone: 'Нест',
        saveDefaultsAction: 'Нигоҳ доштани пешфарзҳо',
        applyToAllAction: 'Татбиқ ба ҳамаи гиреҳҳои дарахт',
        applyToAllHint:
            'Шакл ва ранги ҳамаи гиреҳҳои мавҷудаи ин дарахт, аз ҷумла решаро, бозмегардонад.',
        uploadFile: 'Боргузории файл',
        addLink: 'Иловаи пайванд',
        linkUrlPlaceholder: 'https://...',
        removeAttachment: 'Бартараф кардан',
        uploadFailed: 'Файл боргузорӣ нашуд.',
        pendingUploads: 'Пас аз нигоҳ доштан замима мешаванд',
        attachmentsLabel: 'Файлҳо ва пайвандҳо',
        showAllLabel: 'Ҳамаро нишон додан',
        collapseLabel: 'Пӯшидан',
        previewAction: 'Дидан',
        download: 'Боргирӣ',
        editAction: 'Таҳрир кардан',
        saveAction: 'Нигоҳ доштан',
        cancelAction: 'Бекор кардан',
        savingAction: 'Нигоҳ дошта истодааст…',
        previewErrorLabel: 'Файл бор нашуд',
        addChild: 'Иловаи фарзанд',
        linkNode: 'Пайваст',
        editNode: 'Таҳрир',
        deleteAction: 'Нест кардан',
        copyNode: 'Нусхабардорӣ',
        copyNodeTitle: 'Нусхабардории гузар',
        copyNodeSubtitle:
            'Нусха бардоштани «{name}» ҳамроҳ бо тамоми зершохааш',
        copyTargetLabel: 'Нусхаро пайваст кардан ба',
        copyNewRootOption: '— Решаи нави мустақил —',
        copyAction: 'Нусхабардорӣ',
        linkNodesTitle: 'Пайвасти гузарҳо',
        linkNodesSubtitle: '«{name}»-ро бо гузари дигар пайваст кунед',
        linkDirectionLabel: 'Самти пайванд',
        linkAsChild: 'Ҳадаф фарзанд мешавад',
        linkAsParent: 'Ҳадаф волид мешавад',
        selectTargetLabel: 'Гузари ҳадафро интихоб кунед',
        chooseNodePlaceholder: 'Гузарро интихоб кунед...',
        createLink: 'Эҷоди пайванд',
        spacesTitle: 'Фазоҳо',
        adminSpaceBadge: 'Админ',
        roleEditorBadge: 'Муҳаррир',
        roleViewerBadge: 'Тамошобин',
        sharedByPrefix: 'Мубодила аз ҷониби',
        shareSpaceAction: 'Мубодила',
        shareSpaceTitle: 'Дастрасӣ ба фазо',
        shareSpaceDesc: 'Ба корбари дигар дастрасии тамошо ё таҳрирро диҳед',
        shareIdentifierLabel: 'Логин ё почтаи электронӣ',
        shareIdentifierPlaceholder: 'масалан, alice ё alice@example.com',
        shareRoleLabel: 'Нақш',
        shareAction: 'Кушодани дастрасӣ',
        shareEmptyLabel: 'Ҳанӯз ба ҳеҷ кас дастрасӣ дода нашудааст.',
        revokeAccessAction: 'Бекор кардани дастрасӣ',
        ownerLabel: 'Соҳиб',
        spacesSubtitle: 'Идора ва иваз кардани фазоҳо',
        deleteSpaceTitle: 'Нест кардани фазо',
        spaceNameLabel: 'Номи фазо',
        spaceNamePlaceholder: 'мас. Ғояҳо',
        spaceDescLabel: 'Тавсиф (ихтиёрӣ)',
        spaceDescPlaceholder: 'Ин фазо барои чист...',
        newSpace: 'Фазои нав',
        exportSpace: 'Содироти JSON',
        importSpace: 'Воридоти JSON',
        importBadFormat: 'Ин файл ба содироти фазо монанд нест.',
        createSpace: 'Эҷод кардан',
        deleteNodeTitle: 'Несткунии гузар',
        calculatingAffected: 'Ҳисоби гузарҳои марбут...',
        deleteConfirmQuestion: '«{title}»-ро дар ҳақиқат нест кунем?',
        cascadeNoticeTitle: 'Огоҳӣ: несткунии каскадӣ',
        cascadeNoticeBody:
            'Ҳамроҳи ин гузар боз {n} гузари дастнорас нест мешавад.',
        totalToDelete: 'Ҳамагӣ барои несткунӣ: {n}',
        confirmDelete: 'Нест кардан',
        undoHint: 'Фавран пас аз несткунӣ амалро бекор кардан мумкин аст.',
        languageLabel: 'Забони интерфейс',
        themeLabel: 'Мавзӯи ранг',
        layoutLabel: 'Намуди ҷойгиркунӣ',
        visualsLabel: 'Эффектҳои визуалӣ',
        interfaceLabel: 'Интерфейс',
        curvedEdgesLabel: 'Пайвандҳои каҷ (Bezier)',
        showMinimapLabel: 'Нишон додани харитаи хурд',
        showGridLabel: 'Шабака дар замина',
        showAxesLabel: 'Тирҳои координата',
        showNodeLabelsLabel: 'Номҳои гузарҳо',
        showStatsLabel: 'Нишон додани омор',
        nodeScaleLabel: 'Андозаи гузарҳо',
        reduceMotionLabel: 'Кам кардани аниматсия',
        shortcutsLabel: 'Тугмаҳои зуд',
        shortcutSearch: 'Фокус ба ҷустуҷӯ',
        shortcutGlobalSearch: 'Ҷустуҷӯ дар ҳамаи фазоҳо',
        shortcutAddNode:
            'Иловаи фарзанд (реша, агар чизе интихоб нашуда бошад)',
        shortcutEdit: 'Таҳрири гузари интихобшуда',
        shortcutLink: 'Пайванди гузари интихобшуда',
        shortcutDelete: 'Нест кардани гузари интихобшуда',
        shortcutUndo: 'Бекор кардани нести охирин',
        shortcutAutoLayout: 'Тартиби худкор',
        shortcutClose: 'Пӯшидани равзана / бекор кардани интихоб',
        compactHudLabel: 'Панели компакт',
        resetSettings: 'Барқарорсозии пешфарз',
        close: 'Пӯшидан',
        filterTitle: 'Филтрҳои гузар',
        maxDepthLabel: 'Амиқии макс.',
        maxDepthPlaceholder: 'Бе маҳдудият',
        tagContainsLabel: 'Тег дорои',
        tagPlaceholder: 'мас. origin',
        leavesOnlyLabel: 'Танҳо баргҳо (бе фарзанд)',
        createdRangeLabel: 'Сохташуда дар фосила',
        createdFromLabel: 'Аз',
        createdToLabel: 'То',
        untitledNode: 'Бе ном',
        noDescription: 'Бе тавсиф',
        depthLabel: 'Амиқӣ',
        minimapLabel: 'ХАРИТАИ ХУРД',
        layoutHierarchy: 'Иерархия (дарахт)',
        layoutHierarchyDesc:
            'Дарахти мураттаб: фарзандон зери волиди худ, зина ба зина',
        directionLabel: 'Самт',
        dirDown: 'Аз боло ба поён',
        dirUp: 'Аз поён ба боло',
        dirRight: 'Аз чап ба рост',
        dirLeft: 'Аз рост ба чап',
        colorModeLabel: 'Рангкунии гузарҳо',
        colorByTree: 'Аз рӯи дарахт',
        colorByDepth: 'Аз рӯи зина',
        layoutSpiral: 'Спирали тиллоӣ',
        layoutRings: 'Ҳалқаҳои консентрӣ',
        layoutGrid: 'Шабака',
        layoutRadial: 'Дарахти радиалӣ',
        layoutLayered: 'DAG-и қабатӣ',
        layoutCluster: 'Кластерҳои ситора',
        layoutForce: 'Графи қувва',
        layoutSpiralDesc: 'Спирали Fibonacci дар атрофи марказ',
        layoutRingsDesc: 'Ҳар зина — ҳалқа дар атрофи марказ',
        layoutGridDesc: 'Сатрҳо ва сутунҳои баробар',
        layoutRadialDesc: 'Кафҳои дарахт: амиқӣ ҳамчун радиус',
        layoutLayeredDesc: 'Қабатҳои уфуқӣ аз рӯи амиқии граф',
        layoutClusterDesc: 'Абрҳои ҷудогона барои ҳар реша / гурӯҳи тег',
        layoutForceDesc: 'Симулятсияи физикӣ: дафъ ва ҷалб',
        themeCosmic: 'Кайҳонии торик',
        themeMidnight: 'Нимаи шаб',
        themeCyberpunk: 'Киберпанк',
        themeLight: 'Равшан',
    },
    fa: {
        currentSpace: 'فضای فعلی',
        switchSpace: 'تغییر',
        searchPlaceholder: 'جستجوی گره‌ها بر اساس عنوان، توضیح یا برچسب...',
        nodes: 'گره‌ها',
        edges: 'پیوندها',
        undoDelete: 'لغو حذف',
        autoOrganize: 'چیدمان خودکار',
        addRootNode: 'افزودن ریشه',
        addUserTitle: 'افزودن کاربر',
        newUserHint: 'ورود برای کاربر جدید این سیستم می‌سازد',
        displayNameLabel: 'نام نمایشی',
        userEmailLabel: 'ایمیل',
        createUserAction: 'ایجاد کاربر',
        resetPasswordAction: 'بازنشانی رمز عبور',
        activityLogAction: 'گزارش فعالیت',
        activityLogTitle: 'گزارش فعالیت',
        activityLogDesc: 'اقدامات اخیر مدیر',
        activityLogEmpty: 'هنوز فعالیتی ثبت نشده.',
        activityLogSystemActor: 'سیستم',
        actionUserCreated: 'کاربر {name} را ایجاد کرد',
        actionUserDeleted: 'کاربر {name} را حذف کرد',
        actionPasswordReset: 'رمز عبور {name} را بازنشانی کرد',
        actionSpaceDeleted: 'فضای {name} را حذف کرد',
        spaceActivityLogAction: 'گزارش فعالیت فضا',
        spaceActivityLogTitle: 'فعالیت‌های فضا',
        spaceActivityLogDesc: 'آخرین تغییرات در این فضا',
        spaceFeedNodeCreated: 'گره {title} را ایجاد کرد',
        spaceFeedNodeDeleted: 'گره {titles} را حذف کرد',
        spaceFeedNodeDeletedCascade:
            '{titles} را همراه با فرزندانش حذف کرد ({count} گره در مجموع)',
        spaceFeedStructureMode: 'ساختار فضا را به «{structure}» تغییر داد',
        spaceFeedLinked: '«{child}» را به «{parent}» متصل کرد',
        spaceFeedUnlinked: 'اتصال «{child}» را از «{parent}» قطع کرد',
        spaceFeedCollaboratorAdded: 'به «{name}» دسترسی {role} داد',
        spaceFeedCollaboratorRemoved: 'دسترسی «{name}» را حذف کرد',
        spaceFeedCollaboratorRoleChanged:
            'نقش «{name}» را به «{role}» تغییر داد',
        notificationsAction: 'اعلان‌ها',
        notificationsTitle: 'اعلان‌ها',
        notificationsEmpty: 'اعلان جدیدی نیست.',
        notificationsMarkAllRead: 'علامت‌گذاری همه به‌عنوان خوانده‌شده',
        notificationAccessGranted:
            '{owner} به شما دسترسی {role} به «{space}» داد',
        resetPasswordTitle: 'بازنشانی رمز عبور',
        newPasswordLabel: 'رمز عبور جدید (حداقل ۸ نویسه)',
        confirmPasswordLabel: 'تکرار رمز عبور',
        passwordTooShort: 'رمز عبور باید حداقل ۸ نویسه باشد.',
        passwordsDontMatch: 'رمزهای عبور یکسان نیستند.',
        genericErrorPrefix: 'خطا: {error}',
        settingsTitle: 'تنظیمات سیستم',
        settingsDesc: 'زبان، تم، چیدمان گره‌ها و جلوه‌های بصری',
        tabAppearance: 'ظاهر',
        tabSpace: 'صحنه',
        tabInterface: 'رابط کاربری',
        tabStructure: 'ساختار',
        loginTitle: 'ورود',
        loginSubtitle: 'برای باز کردن فضا اطلاعات خود را وارد کنید',
        usernameLabel: 'نام کاربری',
        passwordLabel: 'رمز عبور',
        signIn: 'ورود',
        signingIn: 'در حال ورود...',
        signOut: 'خروج',
        rememberMe: 'مرا به خاطر بسپار',
        invalidCredentials: 'نام کاربری یا رمز عبور نادرست است.',
        tooManyAttempts: 'تلاش‌های بیش از حد. یک دقیقه صبر کنید.',
        structureLabel: 'ساختار فضا',
        structureHint:
            'فقط بر فضای فعلی اعمال می‌شود — فضاهای دیگر ساختار خود را حفظ می‌کنند.',
        structureTree: 'درخت سخت‌گیرانه',
        structureTreeDesc: 'هر گره حداکثر یک والد دارد. چرخه ممنوع است.',
        structureDag: 'گراف بدون دور (DAG)',
        structureDagDesc:
            'گره می‌تواند چند والد داشته باشد. چرخه همچنان ممنوع است.',
        structureNetwork: 'شبکه آزاد',
        structureLeveled: 'گراف سطح‌بندی‌شده',
        structureLeveledDesc:
            'هر پیوند دقیقاً یک سطح پایین می‌رود. چرخه ذاتاً ناممکن است.',
        structureNetworkDesc:
            'چرخه مجاز است و ریشه‌ای وجود ندارد. حذف فقط گره انتخابی را برمی‌دارد.',
        structureErrSingleParent:
            'تغییر به درخت ممکن نیست: {n} گره بیش از یک والد دارند.',
        structureErrCycle: 'تغییر ممکن نیست: گراف از قبل دارای چرخه است.',
        linkErrSelf: 'گره را نمی‌توان به خودش پیوند داد.',
        linkErrSingleParent:
            'این فضا درخت سخت‌گیرانه است — گره مقصد از قبل والد دارد.',
        linkErrCycle: 'این پیوند یک چرخه ایجاد می‌کند.',
        linkErrLevelGap:
            'این فضا سطح‌بندی‌شده است — پیوند بیش از یک سطح فاصله می‌گیرد.',
        structureErrLevelGap:
            'تغییر به گراف سطح‌بندی‌شده ممکن نیست: {n} پیوند بیش از یک سطح فاصله دارد.',
        undoExpired: 'چیزی برای بازیابی نیست — نسخه پشتیبان منقضی شده است.',
        cancel: 'انصراف',
        addChildTitle: 'افزودن گره فرزند',
        parentLabel: 'والد',
        newRootHint: 'ریشه مستقل جدید',
        titleLabel: 'عنوان',
        descriptionLabel: 'توضیح',
        nodeTitlePlaceholder: 'عنوان گره',
        nodeDetailsPlaceholder: 'جزئیات یا یادداشت...',
        colorLabel: 'رنگ',
        customColorLabel: 'رنگ دلخواه',
        colorAuto: 'خودکار',
        tagsLabel: 'برچسب‌ها (با ویرگول)',
        tagsPlaceholder: 'مثلاً core, feature',
        addNode: 'افزودن گره',
        editNodeTitle: 'ویرایش گره',
        saveChanges: 'ذخیره',
        coordinatesLabel: 'مختصات',
        parentsLabel: 'والدین',
        childrenLabel: 'فرزندان',
        openInMaps: 'باز کردن در نقشه',
        mapSectionLabel: 'نقطه روی نقشه',
        latLabel: 'عرض جغرافیایی',
        lonLabel: 'طول جغرافیایی',
        mapTitleLabel: 'عنوان نقشه',
        positionSectionLabel: 'موقعیت در فضا',
        posXLabel: 'X',
        posYLabel: 'Y',
        rootPositionHint:
            'فقط برای گره‌های ریشه — فرزندان به‌طور خودکار جای‌گذاری می‌شوند.',
        structureSectionLabel: 'ساختار',
        viewStructureAction: 'مشاهده ساختار',
        nodeHistoryAction: 'تاریخچه',
        globalSearchPlaceholder: 'جستجو در همه فضاهای شما...',
        globalSearchEmpty: 'چیزی یافت نشد.',
        globalSearchHint: 'برای جستجو در همه‌جا حداقل ۲ نویسه وارد کنید.',
        globalSearchAction: 'جستجو در همه‌جا',
        nodeHistoryTitle: 'تاریخچه ویرایش',
        nodeHistoryDesc: 'عکس‌های وضعیت پیش از هر تغییر',
        nodeHistoryEmpty: 'هنوز ویرایشی انجام نشده است.',
        restoreVersionAction: 'بازگردانی این نسخه',
        restoredNoticeLabel: 'بازگردانی شد',
        commentsAction: 'نظرات',
        commentsTitle: 'نظرات',
        commentsEmpty: 'هنوز نظری ثبت نشده است.',
        commentsPlaceholder: 'نظر خود را بنویسید...',
        postCommentAction: 'ارسال',
        deleteCommentAction: 'حذف نظر',
        deletedAuthorLabel: 'کاربر حذف‌شده',
        structureEmptyLabel: 'این فضا خالی است.',
        shapeSectionLabel: 'شکل',
        logoSectionLabel: 'لوگو',
        uploadLogoAction: 'بارگذاری لوگو',
        shapeCircle: 'دایره',
        shapeSquare: 'مربع',
        shapeTriangle: 'مثلث',
        shapeDiamond: 'لوزی',
        shapeHexagon: 'شش‌ضلعی',
        treeSettingsAction: 'تنظیمات درخت',
        treeSettingsTitle: 'تنظیمات درخت',
        treeSettingsHint:
            'گره‌های فرزند جدید در هر جای این درخت به‌طور پیش‌فرض این شکل و رنگ را می‌گیرند.',
        defaultShapeLabel: 'شکل پیش‌فرض',
        defaultColorLabel: 'رنگ پیش‌فرض',
        defaultColorNone: 'هیچ‌کدام',
        saveDefaultsAction: 'ذخیره پیش‌فرض‌ها',
        applyToAllAction: 'اعمال به همه گره‌های این درخت',
        applyToAllHint:
            'شکل و رنگ همه گره‌های موجود در این درخت، از جمله ریشه را بازنویسی می‌کند.',
        uploadFile: 'بارگذاری فایل',
        addLink: 'افزودن پیوند',
        linkUrlPlaceholder: 'https://...',
        removeAttachment: 'حذف',
        uploadFailed: 'بارگذاری فایل ناموفق بود.',
        pendingUploads: 'پس از ذخیره پیوست می‌شوند',
        attachmentsLabel: 'فایل‌ها و پیوندها',
        showAllLabel: 'نمایش همه',
        collapseLabel: 'بستن',
        previewAction: 'پیش‌نمایش',
        download: 'دانلود',
        editAction: 'ویرایش',
        saveAction: 'ذخیره',
        cancelAction: 'لغو',
        savingAction: 'در حال ذخیره…',
        previewErrorLabel: 'بارگذاری فایل ناموفق بود',
        addChild: 'افزودن فرزند',
        linkNode: 'پیوند',
        editNode: 'ویرایش',
        deleteAction: 'حذف',
        copyNode: 'کپی',
        copyNodeTitle: 'کپی گره',
        copyNodeSubtitle: 'کپی کردن {name} همراه با کل زیرشاخه‌اش',
        copyTargetLabel: 'اتصال کپی به',
        copyNewRootOption: '— ریشهٔ مستقل جدید —',
        copyAction: 'کپی',
        linkNodesTitle: 'پیوند گره‌ها',
        linkNodesSubtitle: 'اتصال «{name}» به گره دیگر',
        linkDirectionLabel: 'جهت پیوند',
        linkAsChild: 'مقصد فرزند می‌شود',
        linkAsParent: 'مقصد والد می‌شود',
        selectTargetLabel: 'گره مقصد را انتخاب کنید',
        chooseNodePlaceholder: 'یک گره انتخاب کنید...',
        createLink: 'ایجاد پیوند',
        spacesTitle: 'فضاها',
        adminSpaceBadge: 'مدیر',
        roleEditorBadge: 'ویرایشگر',
        roleViewerBadge: 'بیننده',
        sharedByPrefix: 'اشتراک‌گذاری توسط',
        shareSpaceAction: 'اشتراک‌گذاری',
        shareSpaceTitle: 'اشتراک‌گذاری فضا',
        shareSpaceDesc: 'به کاربر دیگری دسترسی مشاهده یا ویرایش بدهید',
        shareIdentifierLabel: 'نام کاربری یا ایمیل',
        shareIdentifierPlaceholder: 'مثلاً alice یا alice@example.com',
        shareRoleLabel: 'نقش',
        shareAction: 'اعطای دسترسی',
        shareEmptyLabel: 'هنوز کسی دسترسی ندارد.',
        revokeAccessAction: 'لغو دسترسی',
        ownerLabel: 'مالک',
        spacesSubtitle: 'مدیریت و جابه‌جایی بین فضاها',
        deleteSpaceTitle: 'حذف فضا',
        spaceNameLabel: 'نام فضا',
        spaceNamePlaceholder: 'مثلاً طوفان فکری',
        spaceDescLabel: 'توضیح (اختیاری)',
        spaceDescPlaceholder: 'هدف این فضا را بنویسید...',
        newSpace: 'فضای جدید',
        exportSpace: 'خروجی JSON',
        importSpace: 'ورودی JSON',
        importBadFormat: 'این فایل خروجی معتبر یک فضا نیست.',
        createSpace: 'ایجاد فضا',
        deleteNodeTitle: 'حذف گره',
        calculatingAffected: 'در حال محاسبه گره‌های متأثر...',
        deleteConfirmQuestion: 'آیا از حذف «{title}» مطمئن هستید؟',
        cascadeNoticeTitle: 'هشدار: حذف آبشاری زیردرخت',
        cascadeNoticeBody:
            'با حذف این گره، {n} گره دسترسی‌ناپذیر دیگر نیز حذف می‌شود.',
        totalToDelete: 'مجموع گره‌های حذف‌شدنی: {n}',
        confirmDelete: 'تأیید حذف',
        undoHint: 'بلافاصله پس از حذف می‌توانید آن را برگردانید.',
        languageLabel: 'زبان رابط',
        themeLabel: 'تم رنگ',
        layoutLabel: 'چیدمان گره‌ها',
        visualsLabel: 'جلوه‌های بصری',
        interfaceLabel: 'رابط کاربری',
        curvedEdgesLabel: 'پیوندهای منحنی (Bezier)',
        showMinimapLabel: 'نمایش نقشه کوچک',
        showGridLabel: 'شبکه پس‌زمینه',
        showAxesLabel: 'محورهای مختصات',
        showNodeLabelsLabel: 'برچسب نام گره‌ها',
        showStatsLabel: 'نمایش آمار',
        nodeScaleLabel: 'اندازه گره‌ها',
        reduceMotionLabel: 'کاهش انیمیشن',
        shortcutsLabel: 'میانبرهای صفحه‌کلید',
        shortcutSearch: 'فوکوس روی جستجو',
        shortcutGlobalSearch: 'جستجو در همه فضاها',
        shortcutAddNode: 'افزودن فرزند (ریشه، اگر چیزی انتخاب نشده باشد)',
        shortcutEdit: 'ویرایش گره انتخاب‌شده',
        shortcutLink: 'اتصال گره انتخاب‌شده',
        shortcutDelete: 'حذف گره انتخاب‌شده',
        shortcutUndo: 'بازگردانی آخرین حذف',
        shortcutAutoLayout: 'چیدمان خودکار',
        shortcutClose: 'بستن پنجره / لغو انتخاب',
        compactHudLabel: 'نوار ابزار فشرده',
        resetSettings: 'بازنشانی به پیش‌فرض',
        close: 'بستن',
        filterTitle: 'فیلتر گره‌ها',
        maxDepthLabel: 'حداکثر عمق',
        maxDepthPlaceholder: 'بدون محدودیت',
        tagContainsLabel: 'برچسب شامل',
        tagPlaceholder: 'مثلاً origin',
        leavesOnlyLabel: 'فقط برگ‌ها (بدون فرزند)',
        createdRangeLabel: 'ایجاد شده در بازه',
        createdFromLabel: 'از',
        createdToLabel: 'تا',
        untitledNode: 'بدون عنوان',
        noDescription: 'بدون توضیح',
        depthLabel: 'عمق',
        minimapLabel: 'نقشه کوچک',
        layoutHierarchy: 'درخت سلسله‌مراتبی',
        layoutHierarchyDesc: 'درخت مرتب: فرزندان زیر والد خود، سطح به سطح',
        directionLabel: 'جهت',
        dirDown: 'از بالا به پایین',
        dirUp: 'از پایین به بالا',
        dirRight: 'از چپ به راست',
        dirLeft: 'از راست به چپ',
        colorModeLabel: 'رنگ‌آمیزی گره‌ها',
        colorByTree: 'بر اساس درخت',
        colorByDepth: 'بر اساس سطح',
        layoutSpiral: 'مارپیچ طلایی',
        layoutRings: 'حلقه‌های هم‌مرکز',
        layoutGrid: 'شبکه',
        layoutRadial: 'درخت شعاعی',
        layoutLayered: 'DAG لایه‌ای',
        layoutCluster: 'صورت‌های فلکی خوشه‌ای',
        layoutForce: 'گراف نیرومند',
        layoutSpiralDesc: 'مارپیچ فیبوناچی حول مرکز',
        layoutRingsDesc: 'هر سطح یک حلقه دور مرکز است',
        layoutGridDesc: 'سطرها و ستون‌های یکنواخت',
        layoutRadialDesc: 'حلقه‌های درخت: عمق به‌عنوان شعاع از ریشه',
        layoutLayeredDesc: 'لایه‌های افقی بر اساس عمق گراف',
        layoutClusterDesc: 'ابرهای جدا برای هر ریشه / گروه برچسب',
        layoutForceDesc: 'شبیه‌سازی فیزیکی: دفع + جذب یال‌ها',
        themeCosmic: 'کیهانی تیره',
        themeMidnight: 'نیمه‌شب آبی',
        themeCyberpunk: 'سایبرپانک بنفش',
        themeLight: 'روشن ساده',
    },
};
