<script setup>
import PageHead from '../components/PageHead.vue';
import TeamPanel from '../components/TeamPanel.vue';
import { session } from '../session.js';

const staff = session.user.is_staff;
</script>

<template>
    <PageHead
        :title="staff ? 'Staff' : 'Team'"
        :sub="staff ? 'BlessedPay\'s own people. A company\'s members are managed on that company\'s page.' : `People who can sign in to ${session.user.company.name}.`"
    />
    <TeamPanel :company-id="staff ? null : session.user.company.id" />
</template>
