<template>
  <b-form-group
    :id="field.key"
    label-cols-sm="4"
    label-cols-lg="3"
    :description="field.field_description"
    :label="field.label"
    :label-for="field.field_key"
    >
    <b-form-textarea :placeholder="field.placeholder" id="field.field_key" v-model="textareadata_assign"  @change="$emit('update:textarea_data', text_area);"></b-form-textarea>
  </b-form-group>
</template>
<script>
import moment from 'moment'
var qs = require('qs');
export default {
  name: 'Textarea',
  props: [
    'field','data_select','textarea_data'
  ],
  mounted () {
  },
  data: () => {
    return {
      selected_record: {},
      key: '',
      text_area:'',
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
    },
    textareadata_assign: {
      get() {
        return this.textarea_data
      },
      set (value) {
        this.text_area = value
      }
    }
  }
}
</script>
