<template>
  <div style="display:none;">
    <b-form-group
      :id="field.key"
      label-cols-sm="4"
      label-cols-lg="3"
      :description="field.field_description"
      :label="field.label"
      :label-for="field.field_key"
      >
      <b-form-input :id="field.field_key" v-model="hidden_data" @keyup="$emit('update:hidden_data', hidden_data);"></b-form-input>
    </b-form-group>
  </div>
</template>
<script>
import moment from 'moment'
var qs = require('qs');
export default {
  name: 'TextField',
  props: [
    'field','data_select','text_data'
  ],
  mounted () {
  },
  data: () => {
    return {
      selected_record: {},
      key: ''
    }
  },
  watch: {
    data_select(newVal) {
        this.selected_record = newVal
    },
  },
  methods: {

  },
  computed: {
    data_assign: {
      get() {
        return this.data_select
      },
      set (value) {
        this.selected_record = value
      }
    },
    key_assign: {
      get() {
        return this.field.field_key
      },
      set (value) {
        this.key = value
      }
    }
  }
}
</script>
