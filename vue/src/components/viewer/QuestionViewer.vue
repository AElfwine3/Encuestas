<template>
    View question
</template>

<script setup>
import { ref } from "@vue/reactivity";


const {question, index, modelValue} = defineProps({
    question: Object,
    index: Number,
    modelValue: [String, Array],
});

const emits = defineEmits(["update:modelValue"]);

let model;
if (question.type === "checkbox") {
    model = ref({});
}
</script>