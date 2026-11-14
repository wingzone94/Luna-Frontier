function createGroupLabel(iconName, text) {
    const label = document.createElement('div');
    const icon = document.createElement('span');
    const title = document.createElement('span');

    label.className = 'm3-platform-group-label';
    icon.className = 'material-symbols-outlined';
    icon.setAttribute('aria-hidden', 'true');
    icon.dataset.icon = iconName;
    title.textContent = text;
    label.append(icon, title);

    return label;
}

function createPlatformList(items) {
    const list = document.createElement('div');
    list.className = 'm3-platform-list';

    items.forEach(item => {
        const label = document.createElement('label');
        const input = document.createElement('input');
        const caption = document.createElement('span');

        label.className = `m3-platform-chip m3-platform-chip--${item.modifier}`;
        input.type = 'checkbox';
        input.name = 'm3_platform[]';
        input.value = item.value;
        caption.textContent = item.label;
        label.append(input, caption);
        list.append(label);
    });

    return list;
}

function createDivider(vertical = false) {
    const divider = document.createElement('div');
    divider.className = `m3-platform-divider${vertical ? ' m3-platform-divider--vertical' : ''}`;
    divider.setAttribute('aria-hidden', 'true');
    return divider;
}

function createPlatformGroup(definition) {
    const group = document.createElement('div');
    group.className = `m3-platform-group${definition.subgroups ? ' m3-platform-subgroup' : ''}`;
    group.append(createGroupLabel(definition.icon, definition.label));

    if (Array.isArray(definition.items)) {
        group.append(createPlatformList(definition.items));
    }

    if (Array.isArray(definition.subgroups)) {
        definition.subgroups.forEach(subgroup => {
            const title = document.createElement('span');
            title.className = 'm3-platform-subgroup-title';
            title.textContent = subgroup.label;
            group.append(title, createPlatformList(subgroup.items || []));
        });
    }

    return group;
}

function createSide(definitions) {
    const side = document.createElement('div');
    side.className = 'm3-platform-side';

    definitions.forEach((definition, index) => {
        if (index > 0) side.append(createDivider());
        side.append(createPlatformGroup(definition));
    });

    return side;
}

export function initSearchPlatforms(container, groups) {
    if (!container || container.dataset.state === 'ready' || !Array.isArray(groups)) return;

    const standardGroups = groups.filter(group => !Array.isArray(group.subgroups));
    const consoleGroups = groups.filter(group => Array.isArray(group.subgroups));
    container.replaceChildren(
        createSide(standardGroups),
        createDivider(true),
        createSide(consoleGroups)
    );
}
